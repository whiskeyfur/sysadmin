<?php

use App\Enums\HealthStatus;
use App\Enums\ServerPlatform;
use App\Exceptions\ServerConnectionException;
use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\ApacheVhost;
use App\Models\Server;
use App\Models\User;
use App\Services\ApacheConfigParser;
use App\Services\ApacheLogParser;
use App\Services\ApacheReportService;
use App\Services\ApacheService;
use App\Services\ServerService;
use App\Services\SettingsService;
use App\Services\SshService;
use Carbon\Carbon;
use phpseclib3\Net\SSH2;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->server = $this->servers->create($this->admin, ['name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'ops', 'apache_enabled' => '1']);
    $this->server->ssh_host_key = 'ssh-ed25519 AAAA';
    $this->server->save();

    // A scripted Debian-style server: commands by substring, files by path; everything else fails.
    $this->ssh = new class () extends SshService {
        public array $files = [];
        public array $inodes = [];
        public array $outputs = [];
        public array $commands = [];
        public array $containers = [];

        public function __construct()
        {
        }

        public function connect(Server $server): SSH2
        {
            return new class ('localhost') extends SSH2 {
                public function disconnect()
                {
                }
            };
        }

        public function platformOf(SSH2 $ssh): ServerPlatform
        {
            return ServerPlatform::Unix;
        }

        public function exec(SSH2 $ssh, string $command): string
        {
            $this->commands[] = $command;

            // `podman exec 'name' sh -c '<command>'`: run <command> against that container's files and outputs.
            if (preg_match("/^(?:docker|podman) exec '([^']+)' sh -c '(.*)'$/s", $command, $m) === 1) {
                if (!isset($this->containers[$m[1]])) {
                    throw new ServerConnectionException("The command exited with status 125: no such container {$m[1]}");
                }

                $host = [$this->files, $this->outputs];
                [$this->files, $this->outputs] = [$this->containers[$m[1]]['files'], $this->containers[$m[1]]['outputs']];

                try {
                    return $this->exec($ssh, str_replace("'\\''", "'", $m[2]));
                } finally {
                    [$this->files, $this->outputs] = $host;
                }
            }

            // Rotated copies: the listing, and zcat (the fake's .gz files are plain text).
            if (preg_match('#for f in \W*(/[^\'\\\\]+)#', $command, $m) === 1) {
                return implode('', array_map(fn ($f) => "$f\n", array_filter(array_keys($this->files), fn ($f) => preg_match('/^' . preg_quote($m[1], '/') . '\\.\\d+(\\.gz)?$/', $f) === 1)));
            }

            if (preg_match("/^zcat -f -- '([^']+)' \\| head -c \\d+$/", $command, $m) === 1) {
                return $this->files[$m[1]] ?? throw new ServerConnectionException("The command exited with status 1: {$m[1]}: No such file");
            }

            if (preg_match("/^(?:cat|tail -c \\+(\\d+)|\\{ ls -di) -- '([^']+)'/", $command, $m) === 1) {
                $path = $m[2];

                if (($this->files[$path] ?? null) === false) {
                    throw new ServerConnectionException("The command exited with status 1: $path: Permission denied");
                }

                if (!isset($this->files[$path])) {
                    throw new ServerConnectionException("The command exited with status 1: $path: No such file or directory");
                }

                if (str_starts_with($command, '{ ls -di')) {
                    return ($this->inodes[$path] ?? '7') . " $path\n" . strlen($this->files[$path]) . "\n";
                }

                return $m[1] === '' ? $this->files[$path] : substr($this->files[$path], (int) $m[1] - 1);
            }

            foreach ($this->outputs as $pattern => $output) {
                if (str_contains($command, $pattern)) {
                    return $output;
                }
            }

            throw new ServerConnectionException('The command exited with status 127: not found');
        }
    };
    $this->ssh->outputs = [
        'date +%z' => "+0000\n",
        'for b in apache2ctl' => "/usr/sbin/apache2ctl\n",
        ' -V 2>&1' => "Server version: Apache/2.4.58 (Ubuntu)\n -D HTTPD_ROOT=\"/etc/apache2\"\n -D SERVER_CONFIG_FILE=\"apache2.conf\"\n",
        ' -S 2>&1' => "ServerRoot: \"/etc/apache2\"\nMain ErrorLog: \"/var/log/apache2/error.log\"\n",
        'DUMP_INCLUDES' => "Included configuration files:\n  (*) /etc/apache2/apache2.conf\n    (222) /etc/apache2/sites-enabled/shop.conf\n",
    ];
    $this->ssh->files = [
        '/etc/apache2/apache2.conf' => "ErrorLog \${APACHE_LOG_DIR}/error.log\nLoadModule status_module /usr/lib/apache2/modules/mod_status.so\nListen 80\n<Location /server-status>\nSetHandler server-status\n</Location>\n",
        '/etc/apache2/sites-enabled/shop.conf' => "<VirtualHost *:80>\nServerName shop\nCustomLog \${APACHE_LOG_DIR}/shop.log combined\nCustomLog \"|/usr/bin/rotatelogs x\" common\n</VirtualHost>\n",
        '/var/log/apache2/error.log' => '',
        '/var/log/apache2/shop.log' => '',
    ];
    $this->apache = fn () => new ApacheService($this->ssh, new ApacheConfigParser(), new ApacheLogParser(), new SettingsService(), $this->clock);
    $this->stamp = fn (int $minutesAgo) => Carbon::instance($this->clock->now())->subMinutes($minutesAgo);
    $this->errorLine = fn (int $minutesAgo, string $level, string $message) => '[' . ($this->stamp)($minutesAgo)->format('D M d H:i:s.u Y') . "] [core:$level] [pid 1] $message\n";
    $this->accessLine = fn (int $minutesAgo, int $status) => '10.0.0.1 - - [' . ($this->stamp)($minutesAgo)->format('d/M/Y:H:i:s O') . "] \"GET / HTTP/1.1\" $status 1000 \"-\" \"-\"\n";
    $this->results = fn (array $results) => collect($results)->keyBy('key');
});

test('log locations come from the configuration Apache reports, with ${VARIABLES} resolved', function () {
    ($this->apache)()->run($this->server);
    $config = $this->server->fresh()->apache_config;

    expect($config['binary'])->toBe('/usr/sbin/apache2ctl')
        ->and($config['error_logs'])->toBe(['/var/log/apache2/error.log' => ['main server']])
        ->and($config['access_logs'])->toBe(['/var/log/apache2/shop.log' => ['*:80']])
        ->and($config['status_url'])->toBe('http://127.0.0.1:80/server-status?auto')
        ->and(implode(' ', $config['notes']))->toContain('piped or syslog');
});

test('reads the logs, judges errors, requests and restarts, and reads only new lines next time', function () {
    $this->ssh->files['/var/log/apache2/error.log'] = ($this->errorLine)(90, 'notice', 'AH00163: Apache/2.4.58 (Ubuntu) configured -- resuming normal operations')
        . ($this->errorLine)(10, 'error', 'AH01630: client denied by server configuration: /x')
        . ($this->errorLine)(9, 'info', 'AH01382: Request header read timeout');
    $this->ssh->files['/var/log/apache2/shop.log'] = str_repeat(($this->accessLine)(10, 200), 18) . ($this->accessLine)(9, 500) . ($this->accessLine)(8, 404);

    $results = ($this->results)(($this->apache)()->run($this->server));

    expect($results['apache_server']->status)->toBe(HealthStatus::Ok)
        ->and($results['apache_server']->summary)->toContain('up 1 h 30 min')
        ->and($results['apache_errors']->summary)->toStartWith('1 error, 0 warnings in the last hour')
        ->and($results['apache_requests']->summary)->toContain('20 requests in the last hour')->toContain('5% server errors (5xx)')
        ->and($results['apache_requests']->status)->toBe(HealthStatus::Warning)
        ->and(ApacheLogEntry::count())->toBe(2)
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(20);

    // New lines only.
    $this->ssh->files['/var/log/apache2/shop.log'] .= ($this->accessLine)(1, 200);
    ($this->apache)()->run($this->server->fresh());

    expect(ApacheLogEntry::count())->toBe(2)
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(21);
});

test('a crashed child is critical; a recent restart is a warning', function () {
    $this->ssh->files['/var/log/apache2/error.log'] = ($this->errorLine)(20, 'notice', 'AH00163: Apache configured -- resuming normal operations')
        . ($this->errorLine)(5, 'notice', 'AH00051: child pid 7 exit signal Segmentation fault (11)');

    $results = ($this->results)(($this->apache)()->run($this->server));

    expect($results['apache_errors']->status)->toBe(HealthStatus::Critical)
        ->and($results['apache_errors']->summary)->toContain('Segmentation fault')
        ->and($results['apache_server']->status)->toBe(HealthStatus::Warning);
});

test('workers: live from mod_status when it answers, else from the error log', function () {
    $this->ssh->outputs['server-status'] = "ServerVersion: Apache/2.4.58 (Ubuntu)\nServerUptimeSeconds: 7200\nBusyWorkers: 9\nIdleWorkers: 1\nScoreboard: WWWWWWWWW_\n";
    $results = ($this->results)(($this->apache)()->run($this->server));

    expect($results['apache_workers']->summary)->toBe('9 of 10 workers busy (90%).')
        ->and($results['apache_workers']->status)->toBe(HealthStatus::Warning)
        ->and($results['apache_server']->summary)->toContain('up 2 h');

    unset($this->ssh->outputs['server-status']);
    $this->ssh->files['/var/log/apache2/error.log'] = ($this->errorLine)(5, 'error', 'AH00161: server reached MaxRequestWorkers setting, consider raising the MaxRequestWorkers setting');
    $results = ($this->results)(($this->apache)()->run($this->server->fresh()));

    expect($results['apache_workers']->status)->toBe(HealthStatus::Warning)
        ->and($results['apache_workers']->summary)->toContain('MaxRequestWorkers');
});

test('degrades gracefully: unreadable logs, or no Apache control program', function () {
    $this->ssh->files['/var/log/apache2/shop.log'] = false;
    $results = ($this->results)(($this->apache)()->run($this->server));

    expect($results['apache_requests']->status)->toBe(HealthStatus::Unknown)
        ->and($results['apache_requests']->summary)->toContain('adm group')
        ->and($results['apache_errors']->status)->toBe(HealthStatus::Ok);

    unset($this->ssh->outputs['for b in apache2ctl']);
    $results = ($this->apache)()->run($this->server->fresh(), rescan: true);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(HealthStatus::Unknown)
        ->and($results[0]->summary)->toContain("control program");
});

test('without the control program, the configuration file set for the server is read with its includes', function () {
    unset($this->ssh->outputs['for b in apache2ctl']);
    $this->servers->update($this->admin, $this->server, ['apache_config_file' => '/etc/httpd/conf/httpd.conf'] + $this->servers->settingsAsInput($this->server));
    $this->ssh->outputs['httpd/conf.modules.d'] = "/etc/httpd/conf.modules.d/00-base.conf\n/etc/httpd/conf.modules.d/README\n";
    $this->ssh->outputs['httpd/conf.d'] = "/etc/httpd/conf.d/shop.conf\n/etc/httpd/conf.d/old/x.conf\n";
    $this->ssh->files += [
        '/etc/httpd/conf/httpd.conf' => "ServerRoot \"/etc/httpd\"\nListen 8080\nInclude conf.modules.d/*.conf\nErrorLog \"logs/error_log\"\nCustomLog \"logs/access_log\" combined\n<Location /server-status>\nSetHandler server-status\n</Location>\nIncludeOptional conf.d/*.conf\n",
        '/etc/httpd/conf.modules.d/00-base.conf' => "LoadModule status_module modules/mod_status.so\n",
        '/etc/httpd/conf.modules.d/README' => "ErrorLog /not/included\n",
        '/etc/httpd/conf.d/shop.conf' => "<VirtualHost *:8080>\nInclude /etc/httpd/conf.d/shop.conf\nCustomLog /var/log/httpd/shop.log common\n</VirtualHost>\n",
        '/etc/httpd/logs/error_log' => ($this->errorLine)(90, 'notice', 'AH00163: Apache/2.4.62 (Rocky Linux) configured -- resuming normal operations'),
        '/etc/httpd/logs/access_log' => '',
        '/var/log/httpd/shop.log' => '',
    ];

    $results = ($this->results)(($this->apache)()->run($this->server->fresh()));
    $config = $this->server->fresh()->apache_config;

    expect($config['binary'])->toBeNull()
        ->and($config['config_file'])->toBe('/etc/httpd/conf/httpd.conf')
        ->and($config['files'])->toBe(3)
        ->and($config['error_logs'])->toBe(['/etc/httpd/logs/error_log' => ['main server']])
        ->and($config['access_logs'])->toBe(['/etc/httpd/logs/access_log' => ['main server'], '/var/log/httpd/shop.log' => ['*:8080']])
        ->and($config['status_url'])->toBe('http://127.0.0.1:8080/server-status?auto')
        ->and($results['apache_server']->summary)->toContain('Apache/2.4.62 (Rocky Linux)');

    $this->servers->update($this->admin, $this->server->fresh(), ['apache_config_file' => '/etc/httpd/nope.conf'] + $this->servers->settingsAsInput($this->server->fresh()));
    $results = ($this->apache)()->run($this->server->fresh());

    expect($results[0]->status)->toBe(HealthStatus::Unknown)
        ->and($results[0]->summary)->toContain("Couldn't read Apache's configuration file /etc/httpd/nope.conf");

    expect(fn () => $this->servers->update($this->admin, $this->server->fresh(), ['apache_config_file' => 'conf/httpd.conf'] + $this->servers->settingsAsInput($this->server->fresh())))
        ->toThrow(DomainException::class, 'full path');
});

test('Apache 2.2 in a podman container: found, remembered, configuration followed from -V, output read with podman logs', function () {
    unset($this->ssh->outputs['for b in apache2ctl']);
    $this->ssh->outputs['for r in docker podman'] = "podman db mariadb:10\npodman apache-image registry.example.com/rhel6/rhel-php-gold:6.10.1\n";
    $this->ssh->outputs['podman logs'] = '';
    $at = fn (int $minutesAgo) => ($this->stamp)($minutesAgo)->utc()->format('Y-m-d\\TH:i:s.u') . '123Z';
    $this->ssh->containers['apache-image'] = [
        'outputs' => [
            'date +%z' => "+0000\n",
            'for b in apache2ctl' => "/usr/sbin/apachectl\n",
            ' -S 2>&1' => "VirtualHost configuration:\nwildcard NameVirtualHosts and _default_ servers:\n_default_:443 localhost (/etc/httpd/conf.d/ssl.conf:74)\nSyntax OK\n",
            ' -V 2>&1' => "Server version: Apache/2.2.15 (Unix)\n -D HTTPD_ROOT=\"/etc/httpd\"\n -D SERVER_CONFIG_FILE=\"conf/httpd.conf\"\n -D DEFAULT_ERRORLOG=\"logs/error_log\"\n",
            'DUMP_INCLUDES' => "Syntax error: unknown define DUMP_INCLUDES\n",
            'httpd/conf.d' => "/etc/httpd/conf.d/ssl.conf\n/etc/httpd/conf.d/php.conf\n",
            'for p in' => "F\nF\nL /dev/stdout\n",
        ],
        'files' => [
            '/etc/httpd/conf/httpd.conf' => "ServerRoot \"/etc/httpd\"\nListen 80\nInclude conf.d/*.conf\nErrorLog logs/error_log\nCustomLog logs/access_log combined\n",
            '/etc/httpd/conf.d/ssl.conf' => "Listen 443\n<VirtualHost _default_:443>\nErrorLog logs/ssl_error_log\n</VirtualHost>\n",
            '/etc/httpd/conf.d/php.conf' => "AddHandler php5-script .php\n",
            '/etc/httpd/logs/error_log' => ($this->errorLine)(10, 'error', 'something broke'),
            '/etc/httpd/logs/ssl_error_log' => '',
        ],
    ];
    $this->ssh->outputs['podman logs'] = $at(3) . ' ' . ($this->accessLine)(3, 200) . $at(2) . ' ' . ($this->accessLine)(2, 500);

    $results = ($this->results)(($this->apache)()->run($this->server));
    $server = $this->server->fresh();
    $config = $server->apache_config;

    expect($server->apache_container)->toBe('podman:apache-image')
        ->and($config['binary'])->toBe('/usr/sbin/apachectl')
        ->and($config['version'])->toBe('Apache/2.2.15 (Unix)')
        ->and($config['files'])->toBe(3)
        ->and($config['error_logs'])->toBe(['/etc/httpd/logs/ssl_error_log' => ['_default_:443'], '/etc/httpd/logs/error_log' => ['main server']])
        ->and($config['access_logs'])->toBe(['/etc/httpd/logs/access_log' => ['main server']])
        ->and($config['streams'])->toBe(['/etc/httpd/logs/access_log' => 'stdout'])
        ->and(implode(' ', $config['notes']))->toContain('Apache 2.2')->toContain('remembered')
        ->and($results['apache_errors']->summary)->toContain('1')
        ->and((int) ApacheTraffic::query()->where('server_id', $server->id)->sum('requests'))->toBe(2)
        ->and(collect($this->ssh->commands)->contains(fn ($c) => str_starts_with($c, "podman logs --timestamps --tail 20000 'apache-image' 2>/dev/null")))->toBeTrue();

    // Next time: the remembered container first (the host isn't searched), and the output continues after the last line.
    $this->ssh->commands = [];
    ($this->apache)()->run($server, rescan: true);

    expect($this->ssh->commands[0])->toStartWith("podman exec 'apache-image' sh -c")
        ->and(collect($this->ssh->commands)->contains(fn ($c) => str_contains($c, 'for r in docker podman')))->toBeFalse()
        ->and(collect($this->ssh->commands)->contains(fn ($c) => str_starts_with($c, "podman logs --timestamps --since '" . $at(2) . "'")))->toBeTrue()
        ->and((int) ApacheTraffic::query()->where('server_id', $server->id)->sum('requests'))->toBe(2);

    // Gone: searched again; nothing found and no configuration file: the message asks for one.
    $this->ssh->containers = [];
    $this->ssh->outputs['for r in docker podman'] = "Error: permission denied\n";
    $results = ($this->apache)()->run($server->fresh(), rescan: true);

    expect($results[0]->summary)->toContain('no longer answers in podman:apache-image')->toContain("main configuration file");
});

test('log files set by hand are read as well, and on their own when Apache can\'t be found', function () {
    $this->servers->update($this->admin, $this->server, ['apache_error_logs' => "/srv/app/error.log\n\n/var/log/apache2/error.log\n/srv/app/error.log", 'apache_access_logs' => '/srv/app/access.log'] + $this->servers->settingsAsInput($this->server));
    $this->ssh->files += ['/srv/app/error.log' => ($this->errorLine)(5, 'error', 'app failed'), '/srv/app/access.log' => ($this->accessLine)(5, 200)];

    expect($this->server->fresh()->apacheLogs('error'))->toBe(['/srv/app/error.log', '/var/log/apache2/error.log']);

    ($this->apache)()->run($this->server->fresh());
    $config = $this->server->fresh()->apache_config;

    expect($config['error_logs'])->toBe(['/var/log/apache2/error.log' => ['main server', 'set in settings'], '/srv/app/error.log' => ['set in settings']])
        ->and($config['access_logs'])->toHaveKey('/srv/app/access.log')
        ->and(ApacheLogEntry::query()->where('source', '/srv/app/error.log')->count())->toBe(1);

    unset($this->ssh->outputs['for b in apache2ctl']);
    $results = ($this->results)(($this->apache)()->run($this->server->fresh(), rescan: true));
    $config = $this->server->fresh()->apache_config;

    expect($results)->toHaveKey('apache_errors')
        ->and(array_keys($config['error_logs']))->toBe(['/srv/app/error.log', '/var/log/apache2/error.log'])
        ->and($config['notes'][0])->toContain('Only the logs set');

    expect(fn () => $this->servers->update($this->admin, $this->server->fresh(), ['apache_access_logs' => "logs/access_log"] + $this->servers->settingsAsInput($this->server->fresh())))
        ->toThrow(DomainException::class, 'full paths');
});

test('virtual hosts are found in the configuration, kept in step with it, and reported from their own logs', function () {
    $this->ssh->files['/etc/apache2/sites-enabled/shop.conf'] = <<<'CONF'
        <VirtualHost *:80>
            ServerName http://Shop.example.com:80
            ServerAlias www.shop.example.com
            DocumentRoot /srv/shop
            CustomLog ${APACHE_LOG_DIR}/shop.log combined
        </VirtualHost>
        <VirtualHost *:80>
            ServerName blog.example.com
        </VirtualHost>
        <VirtualHost *:443>
            ServerName shop.example.com
            SSLEngine on
            DocumentRoot "htdocs"
            ErrorLog ${APACHE_LOG_DIR}/shop-ssl-error.log
        </VirtualHost>
        CONF;
    $this->ssh->files += ['/var/log/apache2/shop-ssl-error.log' => ($this->errorLine)(5, 'error', 'ssl broke')];
    $this->ssh->files['/var/log/apache2/shop.log'] = ($this->accessLine)(5, 200) . ($this->accessLine)(4, 404);
    $this->ssh->outputs[' -S 2>&1'] = "ServerRoot: \"/etc/apache2\"\nMain ErrorLog: \"/var/log/apache2/error.log\"\n";
    $this->ssh->files['/etc/apache2/apache2.conf'] .= "CustomLog \${APACHE_LOG_DIR}/other_vhosts_access.log vhost_combined\n";
    $this->ssh->files['/var/log/apache2/other_vhosts_access.log'] = ($this->accessLine)(3, 500);

    ($this->apache)()->run($this->server);
    $vhosts = ApacheVhost::query()->where('server_id', $this->server->id)->get()->keyBy(fn ($v) => $v->name . ' ' . $v->address);

    expect($vhosts->keys()->sort()->values()->all())->toBe(['blog.example.com *:80', 'shop.example.com *:443', 'shop.example.com *:80'])
        ->and($vhosts['shop.example.com *:80']->aliasList())->toBe(['www.shop.example.com'])
        ->and($vhosts['shop.example.com *:80']->document_root)->toBe('/srv/shop')
        ->and($vhosts['shop.example.com *:80']->accessLogList())->toBe(['/var/log/apache2/shop.log'])
        ->and($vhosts['shop.example.com *:80']->port)->toBe(80)
        ->and($vhosts['shop.example.com *:80']->ssl)->toBeFalse()
        ->and($vhosts['shop.example.com *:443']->ssl)->toBeTrue()
        ->and($vhosts['shop.example.com *:443']->document_root)->toBe('/etc/apache2/htdocs')
        ->and($vhosts['shop.example.com *:443']->config_file)->toBe('/etc/apache2/sites-enabled/shop.conf');

    $reports = new ApacheReportService($this->clock);
    $shop = $reports->report($this->server->fresh(), '24h', $vhosts['shop.example.com *:80']);
    $ssl = $reports->report($this->server->fresh(), '24h', $vhosts['shop.example.com *:443']);
    $logs = $reports->vhostLogs($this->server->fresh(), $vhosts['blog.example.com *:80']);

    expect(array_sum(array_column($shop['rows'], 'requests')))->toBe(2)
        ->and(collect($reports->errorPage($this->server->fresh(), '24h', $vhosts['shop.example.com *:443'])['rows'])->pluck('message')->all())->toBe(['ssl broke'])
        ->and($ssl['log_total'])->toBe(1)
        ->and(array_sum(array_column($ssl['rows'], 'requests')))->toBe(1) // the main server's access log
        ->and($logs['access'])->toBe(['/var/log/apache2/other_vhosts_access.log'])
        ->and($logs['shared'])->toContain('/var/log/apache2/other_vhosts_access.log');

    // Blog removed from the configuration: gone from the list; the others keep their first-seen time.
    $firstSeen = $vhosts['shop.example.com *:80']->first_seen_at->getTimestamp();
    $this->ssh->files['/etc/apache2/sites-enabled/shop.conf'] = str_replace("<VirtualHost *:80>\n    ServerName blog.example.com\n</VirtualHost>\n", '', $this->ssh->files['/etc/apache2/sites-enabled/shop.conf']);
    $this->clock->advance(3600);
    ($this->apache)()->run($this->server->fresh(), rescan: true);
    $after = ApacheVhost::query()->where('server_id', $this->server->id)->get();

    expect($after->pluck('name')->sort()->values()->all())->toBe(['shop.example.com', 'shop.example.com'])
        ->and($after->firstWhere('address', '*:80')->first_seen_at->getTimestamp())->toBe($firstSeen);

    // Apache turned off: its vhosts go too.
    $this->servers->update($this->admin, $this->server->fresh(), ['apache_enabled' => ''] + $this->servers->settingsAsInput($this->server->fresh()));
    expect(ApacheVhost::query()->where('server_id', $this->server->id)->count())->toBe(0);
});

test('a control program whose -S fails is passed over, as when it is missing', function () {
    $this->ssh->outputs[' -S 2>&1'] = "AH00526: Syntax error on line 3 of /etc/apache2/apache2.conf:\nInvalid command 'Foo'\n";
    $results = ($this->apache)()->run($this->server);

    expect($results[0]->status)->toBe(HealthStatus::Unknown)
        ->and($results[0]->summary)->toContain('/usr/sbin/apache2ctl -S failed on the server: AH00526');
});

test('Apache needs SSH; turning SSH off turns Apache off', function () {
    expect(fn () => $this->servers->create($this->admin, ['name' => 'x', 'hostname' => 'x.example.com', 'ssh_enabled' => '', 'apache_enabled' => '1']))->toThrow(DomainException::class, 'over SSH');

    $this->servers->removeModule($this->admin, $this->server, 'ssh');

    expect(Server::query()->find($this->server->id))->toBeNull();
});

test('the report totals requests per interval, with quiet intervals as zero', function () {
    $this->ssh->files['/var/log/apache2/shop.log'] = ($this->accessLine)(30, 200) . ($this->accessLine)(30, 500) . ($this->accessLine)(10, 200);
    ($this->apache)()->run($this->server);

    $report = (new App\Services\ApacheReportService($this->clock))->report($this->server->fresh(), '24h');
    $requests = array_column($report['requests']['Requests'], 1);

    expect($report['bucket_minutes'])->toBe(5)
        ->and(array_sum(array_column($report['rows'], 'requests')))->toBe(3)
        ->and(array_sum(array_column($report['rows'], 'status_5xx')))->toBe(1)
        ->and(count($requests))->toBe(5)
        ->and(in_array(0.0, $requests, true))->toBeTrue();
});

test('each access log is read in the format its CustomLog names, and every request is stored', function () {
    $this->ssh->files['/etc/apache2/apache2.conf'] .= <<<'CONF'
        LogFormat "%v:%p %h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" vhost_combined
        LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" combined
        CustomLog ${APACHE_LOG_DIR}/other_vhosts_access.log vhost_combined

        CONF;
    // The shop redefines "combined" for itself (time taken, then the host), and a second host logs inline.
    $this->ssh->files['/etc/apache2/sites-enabled/shop.conf'] = <<<'CONF'
        <VirtualHost *:80>
            ServerName shop.example.com
            LogFormat "%a %t \"%r\" %>s %B %D %V" combined
            CustomLog ${APACHE_LOG_DIR}/shop.log combined
        </VirtualHost>
        <VirtualHost *:80>
            ServerName api.example.com
            CustomLog ${APACHE_LOG_DIR}/api.log "%{%Y-%m-%d %H:%M:%S}t %h %m %U%q %>s %b"
        </VirtualHost>
        CONF;
    $t = fn (int $minutesAgo, string $format) => ($this->stamp)($minutesAgo)->format($format);
    $this->ssh->files['/var/log/apache2/shop.log'] = '10.0.0.7 [' . $t(5, 'd/M/Y:H:i:s O') . "] \"GET /cart?id=1 HTTP/2.0\" 200 5120 250000 shop.example.com\n";
    $this->ssh->files['/var/log/apache2/api.log'] = $t(4, 'Y-m-d H:i:s') . " 10.0.0.8 POST /v1/items?x=\"y\" 201 -\n";
    $this->ssh->files['/var/log/apache2/other_vhosts_access.log'] = 'blog.example.com:443 10.0.0.9 - - [' . $t(3, 'd/M/Y:H:i:s O') . "] \"GET /feed HTTP/1.1\" 304 0 \"https://ref/\" \"Feed \\\"Reader\\\"\"\n";

    ($this->apache)()->run($this->server);
    $config = $this->server->fresh()->apache_config;
    $requests = App\Models\ApacheAccessEntry::query()->orderBy('requested_at')->get();

    expect($config['access_formats']['/var/log/apache2/shop.log'])->toBe(['%a %t "%r" %>s %B %D %V'])
        ->and($config['access_formats']['/var/log/apache2/other_vhosts_access.log'][0])->toStartWith('%v:%p %h')
        ->and($requests)->toHaveCount(3)
        ->and($requests[0]->only(['client', 'vhost', 'method', 'path', 'protocol', 'status', 'bytes', 'duration_ms']))
        ->toBe(['client' => '10.0.0.7', 'vhost' => 'shop.example.com', 'method' => 'GET', 'path' => '/cart?id=1', 'protocol' => 'HTTP/2.0', 'status' => 200, 'bytes' => 5120, 'duration_ms' => 250])
        // api.log logs no host: it's the only one writing there.
        ->and($requests[1]->only(['client', 'vhost', 'method', 'path', 'status', 'bytes']))
        ->toBe(['client' => '10.0.0.8', 'vhost' => 'api.example.com', 'method' => 'POST', 'path' => '/v1/items?x="y"', 'status' => 201, 'bytes' => 0])
        ->and($requests[1]->requested_at->getTimestamp())->toBe(($this->stamp)(4)->startOfSecond()->getTimestamp())
        ->and($requests[2]->only(['vhost', 'referer', 'agent', 'status']))->toBe(['vhost' => 'blog.example.com:443', 'referer' => 'https://ref/', 'agent' => 'Feed "Reader"', 'status' => 304])
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(3);

    // The report lists them; a host's report only its own from a shared log.
    $reports = new ApacheReportService($this->clock);
    expect(collect($reports->accessPage($this->server->fresh(), '24h')['rows'])->pluck('client')->all())->toBe(['10.0.0.9', '10.0.0.8', '10.0.0.7'])
        ->and($reports->report($this->server->fresh(), '24h')['access_total'])->toBe(3);
});

test('requests are kept for the set number of days, and the ones already read are stored once', function () {
    // Read once before requests were stored: traffic has them, requests don't.
    $this->ssh->files['/var/log/apache2/shop.log'] = ($this->accessLine)(60 * 24 * 3, 200) . ($this->accessLine)(30, 200);
    (new SettingsService())->update($this->admin, ['apache_access_keep_days' => '0']);
    ($this->apache)()->run($this->server);

    expect(App\Models\ApacheAccessEntry::count())->toBe(0)
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(2);

    // Kept 2 days now: the next import adds the new line and, once, the earlier ones within 2 days.
    (new SettingsService())->update($this->admin, ['apache_access_keep_days' => '2']);
    $this->ssh->files['/var/log/apache2/shop.log'] .= ($this->accessLine)(1, 404);
    ($this->apache)()->run($this->server->fresh());

    expect(App\Models\ApacheAccessEntry::query()->orderBy('requested_at')->pluck('status')->all())->toBe([200, 404])
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(3);

    $this->ssh->files['/var/log/apache2/shop.log'] .= ($this->accessLine)(0, 500);
    ($this->apache)()->run($this->server->fresh());
    expect(App\Models\ApacheAccessEntry::count())->toBe(3);

    // Older than the setting: pruned.
    $this->clock->advance(3 * 86400);
    ($this->apache)()->run($this->server->fresh());
    expect(App\Models\ApacheAccessEntry::count())->toBe(0);
});

test('the access and error logs come a page at a time, searched and sorted in the database', function () {
    $now = Carbon::instance($this->clock->now());
    $rows = [];

    for ($i = 0; $i < 250; $i++) {
        $rows[] = ['server_id' => $this->server->id, 'source' => '/var/log/apache2/shop.log', 'requested_at' => $now->copy()->subSeconds(250 - $i)->format('Y-m-d H:i:s'),
            'client' => '10.0.' . intdiv($i, 100) . '.' . ($i % 100), 'vhost' => 'shop', 'method' => 'GET', 'path' => "/item/$i", 'status' => $i % 50 === 0 ? 500 : 200, 'bytes' => $i, 'agent' => $i === 7 ? 'EvilBot/1.0' : 'Mozilla'];
    }

    App\Models\ApacheAccessEntry::query()->insert($rows);
    $reports = new ApacheReportService($this->clock);
    $page = fn (...$args) => $reports->accessPage($this->server, '24h', null, ...$args);

    $first = $page();
    expect($first['total'])->toBe(250)
        ->and($first['pages'])->toBe(3)
        ->and(count($first['rows']))->toBe(ApacheReportService::PAGE_SIZE)
        ->and($first['rows'][0]->path)->toBe('/item/249')
        ->and(count($page(3)['rows']))->toBe(50)
        ->and($page(3)['rows'][49]->path)->toBe('/item/0')
        // Past the end: the last page.
        ->and($page(99)['page'])->toBe(3)
        // Search covers every page, not just the one shown: agent, a status, a path.
        ->and(collect($page(1, 'evilbot')['rows'])->pluck('path')->all())->toBe(['/item/7'])
        ->and($page(1, '500')['total'])->toBe(5)
        ->and($page(1, '/item/12')['total'])->toBe(11)
        // A search with LIKE wildcards means them literally.
        ->and($page(1, '%')['total'])->toBe(0)
        // Sorted by size, smallest first; an unknown column sorts by time.
        ->and($page(1, '', 'size', 'asc')['rows'][0]->bytes)->toBe(0)
        ->and($page(1, '', 'size; drop table x', 'asc')['rows'][0]->path)->toBe('/item/0')
        // Filters: status class, errors, one code; client from its start; localhost hidden.
        ->and($page(1, '', 'time', 'desc', ['statuses' => [5]])['total'])->toBe(5)
        ->and($page(1, '', 'time', 'desc', ['statuses' => [4, 5]])['total'])->toBe(5)
        ->and($page(1, '', 'time', 'desc', ['statuses' => [2]])['total'])->toBe(245)
        ->and($page(1, '', 'time', 'desc', ['client' => '10.0.1.'])['total'])->toBe(100)
        // A whole address matches exactly (10.0.2.4, not 10.0.2.40–49); a partial one from its start.
        ->and($page(1, '', 'time', 'desc', ['client' => '10.0.2.4'])['total'])->toBe(1)
        ->and($page(1, '', 'time', 'desc', ['client' => '10.0.2.4'])['rows'][0]->client)->toBe('10.0.2.4')
        ->and($page(1, '', 'time', 'desc', ['client' => '10.0.2.'])['total'])->toBe(50)
        ->and($page(1, '', 'time', 'desc', ['client' => '10.0.2.0', 'statuses' => [5]])['total'])->toBe(1);

    App\Models\ApacheAccessEntry::query()->insert([
        ['server_id' => $this->server->id, 'source' => 'x', 'requested_at' => $now->format('Y-m-d H:i:s'), 'client' => '127.0.0.1', 'status' => 200, 'bytes' => 0],
        ['server_id' => $this->server->id, 'source' => 'x', 'requested_at' => $now->format('Y-m-d H:i:s'), 'client' => '::1', 'status' => 200, 'bytes' => 0],
        ['server_id' => $this->server->id, 'source' => 'x', 'requested_at' => $now->format('Y-m-d H:i:s'), 'client' => '127.4.4.4', 'status' => 200, 'bytes' => 0],
    ]);

    expect($page()['total'])->toBe(253)
        ->and($page(1, '', 'time', 'desc', ['hide_local' => true])['total'])->toBe(250)
        ->and($page(1, '', 'time', 'desc', ['statuses' => [3]])['total'])->toBe(0)
        ->and($page(1, '', 'time', 'desc', ['statuses' => [9]])['total'])->toBe(253);

    // Banned (any jail, at the last read) and/or protected; none ticked: everyone.
    expect($page(1, '', 'time', 'desc', ['banned' => true])['total'])->toBe(0);

    $this->server->fail2ban_bans = ['sshd' => ['10.0.0.5', '127.0.0.1'], 'web-abusers' => ['10.0.1.7', '10.0.0.5'], 'empty' => []];
    $this->server->save();

    expect($page(1, '', 'time', 'desc', ['banned' => true])['total'])->toBe(3)
        ->and($page(1, '', 'time', 'desc', ['banned' => true, 'hide_local' => true])['total'])->toBe(2)
        ->and($page(1, '', 'time', 'desc', ['protected' => true])['total'])->toBe(0)
        ->and($page(1, '', 'time', 'desc', ['banned' => false, 'protected' => false])['total'])->toBe(253);

    App\Models\Fail2banProtection::query()->create(['server_id' => $this->server->id, 'ip' => '10.0.1.9']);

    expect(collect($page(1, '', 'time', 'desc', ['protected' => true])['rows'])->pluck('client')->all())->toBe(['10.0.1.9'])
        ->and($page(1, '', 'time', 'desc', ['banned' => true, 'protected' => true])['total'])->toBe(4);

    foreach (['note', 'crash', 'error', 'warning'] as $i => $level) {
        ApacheLogEntry::query()->create(['server_id' => $this->server->id, 'source' => '/var/log/apache2/error.log', 'level' => $level, 'logged_at' => $now->copy()->subMinutes(10 - $i), 'message' => "m$i", 'hash' => "h$i"]);
    }

    expect(collect($reports->errorPage($this->server, '24h', null, 1, '', 'level')['rows'])->pluck('level')->all())->toBe(['crash', 'error', 'warning', 'note'])
        ->and(collect($reports->errorPage($this->server, '24h', null, 1, 'crash')['rows'])->pluck('message')->all())->toBe(['m1'])
        // Shown as Apache names it, "Notice", and found by that name.
        ->and(collect($reports->errorPage($this->server, '24h', null, 1, 'Notice')['rows'])->pluck('level')->all())->toBe(['note']);
});

test('rotated copies of the logs are imported once, only what\'s older than what\'s stored', function () {
    $this->ssh->files['/var/log/apache2/shop.log'] = ($this->accessLine)(10, 200);
    $this->ssh->files['/var/log/apache2/error.log'] = ($this->errorLine)(10, 'error', 'today');
    ($this->apache)()->run($this->server);

    expect((int) ApacheTraffic::query()->sum('requests'))->toBe(1);

    // Yesterday's and older copies, one compressed; the copy overlapping today's file is cut at what's stored.
    $this->ssh->files['/var/log/apache2/shop.log.1'] = ($this->accessLine)(60 * 24, 404) . ($this->accessLine)(60 * 20, 200) . ($this->accessLine)(10, 200);
    $this->ssh->files['/var/log/apache2/shop.log.2.gz'] = ($this->accessLine)(60 * 24 * 3, 500);
    $this->ssh->files['/var/log/apache2/shop.log.40.gz'] = ($this->accessLine)(60 * 24 * 40, 200); // older than kept
    $this->ssh->files['/var/log/apache2/error.log.1'] = ($this->errorLine)(60 * 24, 'error', 'yesterday') . ($this->errorLine)(10, 'error', 'today');

    $report = ($this->apache)()->importRotated($this->server->fresh());

    expect($report[0])->toBe('/var/log/apache2/error.log.1: 1 new error log entry.')
        ->and(implode("\n", $report))->toContain('/var/log/apache2/shop.log.40.gz: 0 request(s)')
        ->and(implode("\n", $report))->toContain('/var/log/apache2/shop.log.1: 2 request(s) added to the traffic figures, 2 stored.')
        ->and((int) ApacheTraffic::query()->sum('requests'))->toBe(4)
        ->and((int) ApacheTraffic::query()->sum('status_5xx'))->toBe(1)
        ->and(App\Models\ApacheAccessEntry::count())->toBe(4)
        ->and(ApacheLogEntry::query()->orderBy('logged_at')->pluck('message')->all())->toBe(['yesterday', 'today']);

    // Again: nothing twice.
    ($this->apache)()->importRotated($this->server->fresh());
    expect((int) ApacheTraffic::query()->sum('requests'))->toBe(4)
        ->and(App\Models\ApacheAccessEntry::count())->toBe(4)
        ->and(ApacheLogEntry::count())->toBe(2);
});

test('rotated logs need the logs found first', function () {
    expect(fn () => ($this->apache)()->importRotated($this->server))->toThrow(DomainException::class, 'run its checks first');
});
