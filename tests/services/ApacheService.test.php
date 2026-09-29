<?php

use App\Enums\HealthStatus;
use App\Enums\ServerPlatform;
use App\Exceptions\ServerConnectionException;
use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\Server;
use App\Models\User;
use App\Services\ApacheConfigParser;
use App\Services\ApacheLogParser;
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
        ' -v 2>&1' => "Server version: Apache/2.4.58 (Ubuntu)\n",
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
