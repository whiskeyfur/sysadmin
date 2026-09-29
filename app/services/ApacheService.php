<?php

namespace App\Services;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Enums\ServerPlatform;
use App\Exceptions\ServerConnectionException;
use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\ApacheVhost;
use App\Models\Server;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DateTimeZone;
use DomainException;
use phpseclib3\Net\SSH2;
use Psr\Clock\ClockInterface;

/**
 * Apache monitoring over SSH, from Apache's own configuration and log
 * files. Nothing about locations is assumed:
 *
 * 1. The control program is found by name (apache2ctl, apachectl, httpd).
 *    `-S` gives ServerRoot and the main error log, `-t -D DUMP_INCLUDES`
 *    every configuration file in use; those are read for ErrorLog,
 *    CustomLog and TransferLog. ${VARIABLES} are resolved from Define
 *    lines and from the main error log as `-S` reports it; relative paths
 *    are under ServerRoot. The scan is kept for SCAN_MINUTES. Without a
 *    control program in the SSH user's PATH, the main configuration file
 *    set for the server (apache_config_file) is read with its includes
 *    (ApacheConfigFiles) instead.
 * 2. Each run reads only what's new in each log (RemoteLogs): error log
 *    entries go to apache_log_entries, access log lines are summarised per
 *    5 minutes into apache_traffic.
 * 3. mod_status is used only if the configuration loads it and has a
 *    server-status location, fetched from the server itself over SSH (curl
 *    or wget). Without it, worker figures come from the error log's
 *    "reached MaxRequestWorkers" instead.
 *
 * Each part degrades on its own: an unreadable log or a missing tool is
 * reported in that check's result, and the rest still works.
 */
class ApacheService
{
    public const SCAN_MINUTES = 60;

    public const BUCKET_SECONDS = 300;

    /**
     * The checks, key => label (the Apache page's columns).
     */
    public const CHECKS = [
        'apache_server' => 'Server',
        'apache_errors' => 'Errors',
        'apache_requests' => 'Requests',
        'apache_workers' => 'Workers',
    ];

    private ?RemoteLogs $remote = null;

    /**
     * Where this run reads Apache: the server itself, or the container Apache runs in.
     */
    private ?SshService $shell = null;

    /**
     * Log paths that are really the container's output (path => stdout|stderr), or not a regular file (special).
     *
     * @var array<string, string>
     */
    private array $streams = [];

    public function __construct(
        private readonly SshService $ssh = new SshService(),
        private readonly ApacheConfigParser $config = new ApacheConfigParser(),
        private readonly ApacheLogParser $logs = new ApacheLogParser(),
        private readonly SettingsService $settings = new SettingsService(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function canCheck(Server $server): bool
    {
        return $server->apache_enabled && $server->sshReady();
    }

    /**
     * Scan (when due), read what's new in the logs, and judge.
     *
     * @param bool $rescan scan the configuration even if the last scan is recent
     * @return list<CheckResult>
     */
    public function run(Server $server, bool $rescan = false): array
    {
        try {
            $connection = $this->ssh->connect($server);
        } catch (ServerConnectionException $e) {
            return [new CheckResult('apache', 'Apache', HealthStatus::Critical, "Can't connect over SSH: {$e->getMessage()}")];
        }

        try {
            if ($this->ssh->platformOf($connection) === ServerPlatform::Windows) {
                return [new CheckResult('apache', 'Apache', HealthStatus::Unknown, 'Apache monitoring works on Linux/Unix servers only so far.')];
            }

            $now = Carbon::instance($this->clock->now());
            $config = $server->apache_config;

            if ($rescan || $config === null || $server->apache_scanned_at === null || $server->apache_scanned_at->copy()->addMinutes(self::SCAN_MINUTES)->lessThan($now)) {
                $config = $this->scan($connection, $server->apache_config_file, $server->apache_container, $server->apacheLogs('error'), $server->apacheLogs('access'));
                $server->apache_config = $config;
                $server->apache_container = $config['container'] ?? null;
                $server->apache_scanned_at = $now;

                if (isset($config['vhosts'])) {
                    $this->syncVhosts($server, $config['vhosts'], $now);
                }
            }

            $this->shell = ContainerShell::fromReference($this->ssh, $config['container'] ?? null) ?? $this->ssh;
            $this->remote = new RemoteLogs($this->shell);
            $this->streams = $config['streams'] ?? [];
            $zone = $this->remote()->zone($connection);
            $state = $server->apache_import_state ?? [];
            $problems = ['error' => [], 'access' => []];

            foreach (array_keys($config['error_logs'] ?? []) as $path) {
                $this->importErrorLog($connection, $server, (string) $path, $zone, $state, $problems);
            }

            foreach (array_keys($config['access_logs'] ?? []) as $path) {
                $this->importAccessLog($connection, $server, (string) $path, $state, $problems);
            }

            $status = isset($config['status_url']) ? $this->status($connection, (string) $config['status_url']) : null;
        } catch (DomainException $e) {
            return [new CheckResult('apache', 'Apache', HealthStatus::Unknown, $e->getMessage())];
        } finally {
            $connection->disconnect();
        }

        $server->apache_import_state = $state;
        $server->save();
        $this->prune();

        return $this->evaluate($server, $config, $status, $problems);
    }

    /**
     * What Apache's configuration says: its control program and version,
     * ServerRoot, error and access logs (path => virtual hosts), and the
     * mod_status location if there is one.
     *
     * Where: in the container found last time, else on the server itself,
     * else in a Docker/Podman container that has Apache (remembered in
     * 'container'), else from the configuration file set for the server.
     * "Found" means the control program answers `-S`.
     *
     * @param string|null $configFile Apache's main configuration file (servers.apache_config_file), read
     *                                 directly when the control program can't be used
     * @param string|null $container the container Apache was found in last time ("docker:web")
     * @param list<string> $errorLogs error logs set by hand, read as well (servers.apache_error_logs)
     * @param list<string> $accessLogs access logs set by hand
     * @return array<string, mixed>
     *
     * @throws DomainException when Apache isn't found anywhere, and no configuration file or logs are set
     */
    public function scan(SSH2 $connection, ?string $configFile = null, ?string $container = null, array $errorLogs = [], array $accessLogs = []): array
    {
        try {
            $config = $this->locate($connection, $configFile, $container);
        } catch (DomainException $e) {
            if ($errorLogs === [] && $accessLogs === []) {
                throw $e;
            }

            // The logs set by hand, on the server itself.
            $config = ['binary' => null, 'version' => null, 'container' => null, 'server_root' => null, 'files' => 0, 'error_logs' => [], 'access_logs' => [], 'status_url' => null,
                'notes' => ['Only the logs set in this server\'s Apache settings are read. ' . $e->getMessage()]];
        }

        $label = 'set in settings';

        foreach (['error_logs' => $errorLogs, 'access_logs' => $accessLogs] as $key => $paths) {
            foreach ($paths as $path) {
                $config[$key][$path] = array_values(array_unique([...($config[$key][$path] ?? []), $label]));
            }
        }

        $shell = ContainerShell::fromReference($this->ssh, $config['container'] ?? null);
        $added = array_values(array_diff(array_unique([...$errorLogs, ...$accessLogs]), array_keys($config['streams'] ?? [])));

        if ($shell !== null && $added !== []) {
            $config['streams'] = ($config['streams'] ?? []) + $this->streams($shell, $connection, $added);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private function locate(SSH2 $connection, ?string $configFile, ?string $container): array
    {
        $notes = [];
        $remembered = ContainerShell::fromReference($this->ssh, $container);

        if ($remembered !== null) {
            $found = $this->scanWith($remembered, $connection, $notes);

            if ($found !== null) {
                return $found;
            }

            $notes[] = "Apache no longer answers in {$remembered->reference()}; looked again.";
        }

        $found = $this->scanWith($this->ssh, $connection, $notes);

        if ($found !== null) {
            return $found;
        }

        foreach ($this->containers($connection, $notes) as $shell) {
            if ($shell->reference() === $remembered?->reference()) {
                continue;
            }

            $found = $this->scanWith($shell, $connection, $notes);

            if ($found !== null) {
                $found['notes'][] = "Apache runs in {$shell->runtime} container {$shell->container}; remembered for later scans.";

                return $found;
            }
        }

        if ($configFile !== null && $configFile !== '') {
            $found = $this->scanFile($connection, $configFile);
            $found['notes'] = [...$notes, ...$found['notes']];

            return $found;
        }

        throw new DomainException("Apache's control program (apache2ctl, apachectl or httpd) wasn't found or didn't answer `-S`, on the server or in a Docker/Podman container the SSH user can see. "
            . ($notes === [] ? '' : implode(' ', $notes) . ' ')
            . "Set the path to Apache's main configuration file (httpd.conf or apache2.conf) in this server's Apache settings to read it directly.");
    }

    /**
     * The scan through one shell (the server, or a container); null when
     * the control program isn't there or `-S` fails.
     *
     * @param list<string> $notes why not, for the message when nothing works
     * @return array<string, mixed>|null
     */
    private function scanWith(SshService $shell, SSH2 $connection, array &$notes): ?array
    {
        $remote = new RemoteLogs($shell);
        $where = $shell instanceof ContainerShell ? "in {$shell->runtime} container {$shell->container}" : 'on the server';
        $binary = trim(strtok($remote->run($connection, 'for b in apache2ctl apachectl httpd; do command -v "$b" && break; done'), "\n") ?: '');

        if ($binary === '') {
            return null;
        }

        $bin = escapeshellarg($binary);
        $runtime = $this->config->runtime($output = $remote->run($connection, "$bin -S 2>&1"));

        if (!$this->config->answered($output)) {
            $first = trim((string) strtok(trim($output), "\n"));
            $notes[] = "$binary -S failed $where" . ($first === '' ? '.' : ': ' . mb_substr($first, 0, 200) . '.');

            return null;
        }

        $scanNotes = [];
        $compiled = $this->config->compiled($remote->run($connection, "$bin -V 2>&1"));
        $version = $compiled['version'];
        $runtime['server_root'] ??= $compiled['root'];
        $files = $this->config->includedFiles($remote->run($connection, "$bin -t -D DUMP_INCLUDES 2>&1"));
        $directives = [];
        $variables = [];
        $fileCount = count($files);

        foreach ($files as $file) {
            try {
                $text = $shell->exec($connection, 'cat -- ' . escapeshellarg($file) . ' 2>&1');
            } catch (ServerConnectionException $e) {
                $scanNotes[] = "Couldn't read $file.";

                continue;
            }

            foreach ($this->config->directives($text) as $directive) {
                $directives[] = $directive + ['file' => $file];
            }
        }

        $mainFile = $compiled['config_file'] === null ? null
            : (str_starts_with($compiled['config_file'], '/') || $runtime['server_root'] === null ? $compiled['config_file'] : rtrim($runtime['server_root'], '/') . '/' . $compiled['config_file']);

        if ($files === [] && $mainFile !== null) {
            // Apache 2.2 (no DUMP_INCLUDES): follow the includes from the main file ourselves.
            try {
                $read = (new ApacheConfigFiles($shell, $this->config))->read($connection, $mainFile);
                $directives = $this->config->directives($read['text']);
                $variables = $read['variables'];
                $fileCount = $read['files'];
                $runtime['server_root'] ??= $read['server_root'];
                $scanNotes[] = "$binary can't list its configuration files (Apache 2.2); read $mainFile and its includes.";
                $scanNotes = [...$scanNotes, ...$read['notes']];
            } catch (DomainException $e) {
                $scanNotes[] = $e->getMessage();
            }
        } elseif ($files === []) {
            $scanNotes[] = "Couldn't list Apache's configuration files ($binary -t -D DUMP_INCLUDES); only the main error log is known.";
        }

        $hasMainErrorLog = collect($directives)->contains(fn ($d) => $d['name'] === 'errorlog' && $d['vhost'] === null);

        if ($runtime['main_error_log'] === null && !$hasMainErrorLog && $compiled['error_log'] !== null && $runtime['server_root'] !== null) {
            // No ErrorLog in the main server: Apache's built-in default.
            $runtime['main_error_log'] = str_starts_with($compiled['error_log'], '/') ? $compiled['error_log'] : rtrim($runtime['server_root'], '/') . '/' . $compiled['error_log'];
        }

        $found = ['binary' => $binary, 'version' => $version, 'container' => null] + $this->logsAndStatus($directives, $runtime, $fileCount, $scanNotes, $variables);

        if ($shell instanceof ContainerShell) {
            $found['container'] = $shell->reference();
            $found['streams'] = $this->streams($shell, $connection, [...array_keys($found['error_logs']), ...array_keys($found['access_logs'])]);
        }

        return $found;
    }

    /**
     * Running containers the SSH user can see (docker, then podman), those
     * whose name or image mentions Apache/httpd first.
     *
     * @param list<string> $notes
     * @return list<ContainerShell>
     */
    private function containers(SSH2 $connection, array &$notes): array
    {
        $output = (new RemoteLogs($this->ssh))->run($connection, 'for r in docker podman; do command -v "$r" >/dev/null 2>&1 || continue; $r ps --format \'{{.Names}} {{.Image}}\' 2>&1 | sed "s/^/$r /"; done');
        $found = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^(docker|podman) ([A-Za-z0-9][A-Za-z0-9_.-]*)(?:,\S*)? (\S+)$/', trim($line), $m) === 1) {
                $found[] = ['shell' => new ContainerShell($this->ssh, $m[1], $m[2]), 'apache' => preg_match('/httpd|apache/i', "$m[2] $m[3]") === 1];
            } elseif (stripos($line, 'permission denied') !== false) {
                $notes[] = 'The SSH user may not use ' . strtok($line, ' ') . ' (permission denied; it needs e.g. the docker group).';
            }
        }

        usort($found, fn ($a, $b) => $b['apache'] <=> $a['apache']);

        return array_slice(array_column($found, 'shell'), 0, 20);
    }

    /**
     * Which log paths are really the container's stdout/stderr (e.g. the
     * official httpd image's /proc/self/fd/2, or a symlink to /dev/stderr),
     * read with `docker logs` instead; 'special' for anything else that
     * isn't a regular file, which must not be read like one.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private function streams(ContainerShell $shell, SSH2 $connection, array $paths): array
    {
        $streams = [];
        $check = [];

        foreach (array_unique($paths) as $path) {
            $stream = ContainerShell::streamOf($path);

            if ($stream !== null) {
                $streams[$path] = $stream;
            } else {
                $check[] = $path;
            }
        }

        if ($check === []) {
            return $streams;
        }

        // One line per path: "L <target>" (symlink, one level), "F" (file), "S" (something else), "N" (missing).
        $quoted = implode(' ', array_map('escapeshellarg', $check));
        $lines = preg_split('/\R/', (new RemoteLogs($shell))->run($connection, "for p in $quoted; do if [ -L \"\$p\" ]; then echo \"L \$(readlink \"\$p\")\"; elif [ -f \"\$p\" ]; then echo F; elif [ -e \"\$p\" ]; then echo S; else echo N; fi; done")) ?: [];

        foreach ($check as $i => $path) {
            $line = trim((string) ($lines[$i] ?? ''));

            if (str_starts_with($line, 'L ')) {
                $target = substr($line, 2);
                $target = str_starts_with($target, '/') ? $target : dirname($path) . "/$target";
                $stream = ContainerShell::streamOf($target);

                if ($stream !== null) {
                    $streams[$path] = $stream;
                }
            } elseif ($line === 'S') {
                $streams[$path] = 'special';
            }
        }

        return $streams;
    }

    /**
     * scan() without the control program: from the main configuration file,
     * following its includes (ApacheConfigFiles). Without `-S`, ServerRoot
     * comes from the file and the main error log from its ErrorLog.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException when the file can't be read
     */
    private function scanFile(SSH2 $connection, string $configFile): array
    {
        $read = (new ApacheConfigFiles($this->ssh, $this->config))->read($connection, $configFile);
        $notes = ["Apache's control program isn't in the SSH user's PATH; read $configFile and its includes directly.", ...$read['notes']];
        $runtime = ['server_root' => $read['server_root'], 'main_error_log' => null];

        return ['binary' => null, 'config_file' => $configFile, 'version' => null, 'container' => null]
            + $this->logsAndStatus($this->config->directives($read['text']), $runtime, $read['files'], $notes, $read['variables']);
    }

    /**
     * Log files and the mod_status URL from the configuration's directives.
     *
     * @param list<array<string, mixed>> $directives
     * @param array{server_root: ?string, main_error_log: ?string} $runtime
     * @param list<string> $notes
     * @param array<string, string> $environment variables from outside the configuration (Debian's envvars); Define wins
     * @return array<string, mixed>
     */
    private function logsAndStatus(array $directives, array $runtime, int $fileCount, array $notes, array $environment = []): array
    {
        $variables = $this->variables($directives, $runtime['main_error_log']) + $environment;
        $resolve = function (string $path) use ($variables, $runtime, &$notes): ?string {
            if (str_starts_with($path, '|') || str_starts_with(strtolower($path), 'syslog')) {
                $notes[] = "$path: piped or syslog logging can't be read as a file.";

                return null;
            }

            $path = (string) preg_replace_callback('/\$\{(\w+)\}/', fn ($m) => $variables[$m[1]] ?? $m[0], $path);

            if (str_contains($path, '${')) {
                $notes[] = "$path: couldn't resolve the variable.";

                return null;
            }

            return str_starts_with($path, '/') || $runtime['server_root'] === null ? $path : rtrim($runtime['server_root'], '/') . '/' . $path;
        };

        $errorLogs = [];
        $accessLogs = [];

        if ($runtime['main_error_log'] !== null && str_starts_with($runtime['main_error_log'], '/')) {
            $errorLogs[$runtime['main_error_log']] = ['main server'];
        }

        $statusLocation = null;
        $statusLoaded = false;
        $port = null;

        foreach ($directives as $d) {
            $where = $d['vhost'] ?? 'main server';

            switch ($d['name']) {
                case 'errorlog':
                    $path = isset($d['args'][0]) ? $resolve($d['args'][0]) : null;

                    if ($path !== null) {
                        $errorLogs[$path] = array_values(array_unique([...($errorLogs[$path] ?? []), $where]));
                    }

                    break;

                case 'customlog':
                case 'transferlog':
                    $path = isset($d['args'][0]) ? $resolve($d['args'][0]) : null;

                    if ($path !== null) {
                        $accessLogs[$path] = array_values(array_unique([...($accessLogs[$path] ?? []), $where]));
                    }

                    break;

                case 'sethandler':
                    if (strtolower($d['args'][0] ?? '') === 'server-status' && $d['location'] !== null && $d['vhost'] === null) {
                        $statusLocation = $d['location'];
                    }

                    break;

                case 'loadmodule':
                    $statusLoaded = $statusLoaded || ($d['args'][0] ?? '') === 'status_module';

                    break;

                case 'listen':
                    if ($port === null && preg_match('/(?:^|:)(\d+)$/', $d['args'][0] ?? '', $m) === 1) {
                        $port = (int) $m[1];
                    }

                    break;
            }
        }

        $vhosts = $this->vhosts($directives, $resolve);
        $statusUrl = $statusLoaded && $statusLocation !== null && $port !== null ? "http://127.0.0.1:$port" . rtrim($statusLocation, '/') . '?auto' : null;
        $notes[] = $statusUrl !== null
            ? "mod_status: $statusLocation, read from the server itself."
            : 'mod_status: not configured (' . ($statusLoaded ? 'no server-status location in the main server' : 'module not loaded') . '); worker figures come from the error log.';

        return [
            'server_root' => $runtime['server_root'],
            'files' => $fileCount,
            'error_logs' => $errorLogs,
            'access_logs' => $accessLogs,
            'status_url' => $statusUrl,
            'vhosts' => $vhosts,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /**
     * The <VirtualHost> blocks: ServerName (no scheme or port) and aliases,
     * address and port, SSL (SSLEngine on, or port 443), DocumentRoot, and
     * their own logs (none: the main server's).
     *
     * @param list<array<string, mixed>> $directives
     * @param callable(string): ?string $resolve a path as written to a full path
     * @return list<array{name: ?string, aliases: list<string>, address: string, port: ?int, ssl: bool, document_root: ?string, error_log: ?string, access_logs: list<string>, config_file: ?string}>
     */
    public function vhosts(array $directives, callable $resolve): array
    {
        $vhosts = [];

        foreach ($directives as $d) {
            if (($d['vhost_index'] ?? null) === null) {
                continue;
            }

            $key = ($d['file'] ?? '') . '#' . $d['vhost_index'];
            $address = (string) $d['vhost'];
            $vhost = $vhosts[$key] ?? [
                'name' => null,
                'aliases' => [],
                'address' => $address,
                'port' => preg_match('/(?:^|:)(\d+)$/', (string) strtok($address, " \t"), $m) === 1 ? (int) $m[1] : null,
                'ssl' => false,
                'document_root' => null,
                'error_log' => null,
                'access_logs' => [],
                'config_file' => $d['file'] ?? null,
            ];
            $first = (string) ($d['args'][0] ?? '');

            switch ($d['name']) {
                case 'servername':
                    $vhost['name'] = $this->hostName($first);

                    break;

                case 'serveralias':
                    $vhost['aliases'] = array_values(array_unique([...$vhost['aliases'], ...array_filter(array_map(fn ($a) => $this->hostName((string) $a), $d['args']))]));

                    break;

                case 'sslengine':
                    $vhost['ssl'] = strtolower($first) === 'on';

                    break;

                case 'documentroot':
                    $vhost['document_root'] = $first === '' ? null : $resolve($first);

                    break;

                case 'errorlog':
                    $vhost['error_log'] = $first === '' ? null : $resolve($first);

                    break;

                case 'customlog':
                case 'transferlog':
                    $path = $first === '' ? null : $resolve($first);

                    if ($path !== null) {
                        $vhost['access_logs'] = array_values(array_unique([...$vhost['access_logs'], $path]));
                    }

                    break;
            }

            $vhosts[$key] = $vhost;
        }

        return array_values(array_map(fn ($v) => ['ssl' => $v['ssl'] || $v['port'] === 443] + $v, $vhosts));
    }

    /**
     * "https://www.example.com:443" as "www.example.com"; wildcards (in aliases) kept.
     */
    private function hostName(string $value): ?string
    {
        $host = strtolower((string) preg_replace(['#^[a-z][a-z0-9+.-]*://#i', '#:\d+$#', '#/.*$#'], '', trim($value)));

        return $host === '' ? null : $host;
    }

    /**
     * Keep the server's vhosts in step with what the scan found: new ones
     * added, the rest updated, those gone from the configuration removed.
     *
     * @param list<array<string, mixed>> $found
     */
    private function syncVhosts(Server $server, array $found, Carbon $now): void
    {
        $existing = ApacheVhost::query()->where('server_id', $server->id)->get()->keyBy(fn (ApacheVhost $v) => ($v->name ?? '') . '|' . $v->address);
        $seen = [];

        foreach ($found as $vhost) {
            $key = ($vhost['name'] ?? '') . '|' . $vhost['address'];

            if (isset($seen[$key])) {
                continue; // the same name and address twice: Apache uses the first
            }

            $seen[$key] = true;
            /** @var ApacheVhost $row */
            $row = $existing[$key] ?? new ApacheVhost(['server_id' => $server->id, 'first_seen_at' => $now]);
            $row->fill([
                'name' => $vhost['name'],
                'aliases' => $vhost['aliases'] === [] ? null : implode("\n", $vhost['aliases']),
                'address' => $vhost['address'],
                'port' => $vhost['port'],
                'ssl' => $vhost['ssl'],
                'document_root' => $vhost['document_root'],
                'error_log' => $vhost['error_log'],
                'access_logs' => $vhost['access_logs'] === [] ? null : implode("\n", $vhost['access_logs']),
                'config_file' => $vhost['config_file'],
                'last_seen_at' => $now,
            ])->save();
        }

        foreach ($existing as $key => $row) {
            if (!isset($seen[$key])) {
                $row->delete();
            }
        }
    }

    /**
     * ${VARIABLES} used in log paths: from Define lines, and from the main
     * ErrorLog as written (e.g. ${APACHE_LOG_DIR}/error.log) against the
     * path `-S` reports for it (/var/log/apache2/error.log).
     *
     * @param list<array<string, mixed>> $directives as from ApacheConfigParser::directives() (name, args, vhost)
     * @return array<string, string>
     */
    public function variables(array $directives, ?string $mainErrorLog): array
    {
        $variables = [];

        foreach ($directives as $d) {
            if ($d['name'] === 'define' && isset($d['args'][0], $d['args'][1])) {
                $variables[$d['args'][0]] = $d['args'][1];
            }
        }

        if ($mainErrorLog === null) {
            return $variables;
        }

        foreach ($directives as $d) {
            $template = $d['args'][0] ?? '';

            if ($d['name'] !== 'errorlog' || $d['vhost'] !== null || !str_contains($template, '${')) {
                continue;
            }

            $names = [];
            $pattern = (string) preg_replace_callback('/\\\\\$\\\\\{(\w+)\\\\\}/', function ($m) use (&$names) {
                $names[] = $m[1];

                return '(.+?)';
            }, preg_quote($template, '#'));

            if (preg_match("#^$pattern$#", $mainErrorLog, $m) === 1) {
                foreach ($names as $i => $name) {
                    $variables[$name] ??= $m[$i + 1];
                }
            }
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $state
     * @param array{error: list<string>, access: list<string>} $problems
     */
    private function importErrorLog(SSH2 $connection, Server $server, string $path, DateTimeZone $zone, array &$state, array &$problems): void
    {
        try {
            $text = $this->readLog($connection, $path, $state);
        } catch (ServerConnectionException $e) {
            $problems['error'][] = $this->readProblem($path, $e);

            return;
        }

        $cutoff = Carbon::instance($this->clock->now())->subDays(HealthCheckService::RETENTION_DAYS);
        $batch = [];
        $repeats = ['second' => null, 'counts' => []];

        foreach ($this->logs->errorEntries($text, $zone) as $entry) {
            if ($entry['time']->greaterThanOrEqualTo($cutoff)) {
                $batch[] = $entry;
            }

            if (count($batch) >= 500) {
                $this->remote()->store(ApacheLogEntry::class, $server, $path, $batch, $repeats);
                $batch = [];
            }
        }

        $this->remote()->store(ApacheLogEntry::class, $server, $path, $batch, $repeats);
    }

    /**
     * @param array<string, mixed> $state
     * @param array{error: list<string>, access: list<string>} $problems
     */
    private function importAccessLog(SSH2 $connection, Server $server, string $path, array &$state, array &$problems): void
    {
        try {
            $text = $this->readLog($connection, $path, $state);
        } catch (ServerConnectionException $e) {
            $problems['access'][] = $this->readProblem($path, $e);

            return;
        }

        $cutoff = Carbon::instance($this->clock->now())->subDays(HealthCheckService::RETENTION_DAYS)->getTimestamp();
        $buckets = [];
        $skipped = 0;

        foreach ($this->logs->accessLines($text, $skipped) as $line) {
            if ($line['time'] < $cutoff) {
                continue;
            }

            $bucket = intdiv($line['time'], self::BUCKET_SECONDS) * self::BUCKET_SECONDS;
            $counts = $buckets[$bucket] ?? ['requests' => 0, 'bytes' => 0, 'status_2xx' => 0, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0];
            $counts['requests']++;
            $counts['bytes'] += $line['bytes'];
            $class = 'status_' . intdiv($line['status'], 100) . 'xx';

            if (isset($counts[$class])) {
                $counts[$class]++;
            }

            $buckets[$bucket] = $counts;
        }

        if ($skipped > 0 && $buckets === []) {
            $problems['access'][] = "$path: $skipped line(s) in a format without the usual [time] \"request\" status bytes.";
        }

        foreach ($buckets as $bucket => $counts) {
            $at = Carbon::createFromTimestamp($bucket)->format('Y-m-d H:i:s');
            $row = ApacheTraffic::query()->where('server_id', $server->id)->where('log', $path)->where('bucket_at', $at)->first();

            if ($row instanceof ApacheTraffic) {
                foreach ($counts as $field => $value) {
                    $row->{$field} += $value;
                }

                $row->save();
            } else {
                ApacheTraffic::query()->insert(['server_id' => $server->id, 'log' => $path, 'bucket_at' => $at] + $counts);
            }
        }
    }

    /**
     * mod_status's figures, fetched from the server itself; null if that fails.
     *
     * @return array<string, string>|null
     */
    private function status(SSH2 $connection, string $url): ?array
    {
        $quoted = escapeshellarg($url);

        // The default virtual host may redirect (e.g. to https) or not allow it: also try as "localhost", then following redirects.
        foreach (['', "-H 'Host: localhost'", '-L -k'] as $options) {
            $body = $this->remote()->run($connection, "if command -v curl >/dev/null; then curl -s -m 5 $options $quoted; elif command -v wget >/dev/null; then wget -q -T 5 -O - $quoted; fi");
            $status = [];

            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (preg_match('/^([A-Za-z][A-Za-z0-9 _]*):\s?(.*)$/', trim($line), $m) === 1) {
                    $status[$m[1]] = trim($m[2]);
                }
            }

            if (isset($status['Scoreboard']) || isset($status['BusyWorkers'])) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string>|null $status
     * @param array{error: list<string>, access: list<string>} $problems
     * @return list<CheckResult>
     */
    public function evaluate(Server $server, array $config, ?array $status, array $problems): array
    {
        $now = Carbon::instance($this->clock->now());
        $hourAgo = $now->copy()->subHour();
        $recent = ApacheLogEntry::query()->where('server_id', $server->id)->where('logged_at', '>=', $hourAgo)->get();

        return [
            $this->serverResult($server, $config, $status, $now),
            $this->errorsResult($config, $recent, $problems['error']),
            $this->requestsResult($server, $config, $hourAgo, $problems['access']),
            $this->workersResult($status, $recent),
        ];
    }

    /**
     * The version from the last start in the error log ("Apache/2.4.58 (Ubuntu) configured"), for servers
     * read without the control program.
     */
    private function versionFromLog(Server $server): ?string
    {
        $message = ApacheLogEntry::query()->where('server_id', $server->id)->where('message', 'like', '%resuming normal operations%')->orderByDesc('logged_at')->value('message');

        return is_string($message) && preg_match('#(Apache/[\d.]+(?: \([^)]*\))?)#', $message, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string>|null $status
     */
    private function serverResult(Server $server, array $config, ?array $status, Carbon $now): CheckResult
    {
        $version = $status['ServerVersion'] ?? $config['version'] ?? $this->versionFromLog($server) ?? 'Apache';
        $window = $this->settings->integer(SettingsService::APACHE_RESTART_WARNING_MINUTES);
        $details = ['version' => $version, 'binary' => $config['binary'] ?? null];

        if (isset($status['ServerUptimeSeconds']) && is_numeric($status['ServerUptimeSeconds'])) {
            $uptime = (int) $status['ServerUptimeSeconds'];
            $started = $now->copy()->subSeconds($uptime);
        } else {
            // "AH00163: Apache/2.4.58 (Ubuntu) configured -- resuming normal operations" at each (re)start.
            /** @var ApacheLogEntry|null $start */
            $start = ApacheLogEntry::query()->where('server_id', $server->id)->where('message', 'like', '%resuming normal operations%')->orderByDesc('logged_at')->first();
            $started = $start instanceof ApacheLogEntry ? $start->logged_at : null;
            $uptime = $started === null ? null : $now->getTimestamp() - $started->getTimestamp();
        }

        if ($uptime === null) {
            return new CheckResult('apache_server', 'Server', HealthStatus::Ok, "$version. No start in the error log in the last " . HealthCheckService::RETENTION_DAYS . ' days.', null, null, $details);
        }

        $summary = "$version, up " . Checks\ServerStatusCheck::duration($uptime) . ' (since ' . \App\Utils\LocalTime::format($started) . ').';

        if ($uptime < $window * 60) {
            return new CheckResult('apache_server', 'Server', HealthStatus::Warning, "$summary Restarted recently; check the error log.", (float) $uptime, 's', $details);
        }

        return new CheckResult('apache_server', 'Server', HealthStatus::Ok, $summary, (float) $uptime, 's', $details);
    }

    /**
     * @param array<string, mixed> $config
     * @param \Illuminate\Support\Collection<int, ApacheLogEntry> $recent
     * @param list<string> $problems
     */
    private function errorsResult(array $config, $recent, array $problems): CheckResult
    {
        if (($config['error_logs'] ?? []) === [] || count($problems) >= count($config['error_logs'] ?? [])) {
            return new CheckResult('apache_errors', 'Errors', HealthStatus::Unknown, $problems === [] ? 'No error log found in the configuration.' : implode(' ', $problems));
        }

        $crashes = $recent->where('level', 'crash');
        $errors = $recent->where('level', 'error');
        $warnings = $recent->where('level', 'warning')->count();
        $limit = $this->settings->integer(SettingsService::APACHE_ERRORS_WARNING);
        $summary = count($errors) . ' error' . (count($errors) === 1 ? '' : 's') . ", $warnings warning" . ($warnings === 1 ? '' : 's') . ' in the last hour.';

        if ($crashes->isNotEmpty()) {
            $status = HealthStatus::Critical;
            $summary = count($crashes) . ' crashed child process' . (count($crashes) === 1 ? '' : 'es') . ' in the last hour: ' . mb_substr((string) ($crashes->last()->message ?? ''), 0, 200) . ' ' . $summary;
        } else {
            $status = count($errors) >= $limit ? HealthStatus::Warning : HealthStatus::Ok;

            if ($errors->isNotEmpty()) {
                $summary .= ' Latest: ' . mb_substr((string) ($errors->sortBy('logged_at')->last()->message ?? ''), 0, 200);
            }
        }

        return new CheckResult('apache_errors', 'Errors', $status, $summary . ($problems === [] ? '' : ' ' . implode(' ', $problems)), (float) (count($errors) + count($crashes)), 'errors', ['warnings' => $warnings]);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $problems
     */
    private function requestsResult(Server $server, array $config, Carbon $since, array $problems): CheckResult
    {
        if (($config['access_logs'] ?? []) === [] || count($problems) >= count($config['access_logs'] ?? [])) {
            return new CheckResult('apache_requests', 'Requests', HealthStatus::Unknown, $problems === [] ? 'No access log found in the configuration.' : implode(' ', $problems));
        }

        $rows = ApacheTraffic::query()->where('server_id', $server->id)->where('bucket_at', '>=', $since)->get();
        $requests = (int) $rows->sum('requests');
        $failed = (int) $rows->sum('status_5xx');
        $percent = $requests === 0 ? 0.0 : round($failed / $requests * 100, 1);
        $summary = number_format($requests) . ' request' . ($requests === 1 ? '' : 's') . ' in the last hour (' . Checks\FileIoCheck::size((int) $rows->sum('bytes')) . "), $percent% server errors (5xx).";
        $warning = $this->settings->integer(SettingsService::APACHE_5XX_WARNING_PERCENT);
        $critical = $this->settings->integer(SettingsService::APACHE_5XX_CRITICAL_PERCENT);
        // Too few requests for a percentage to mean anything.
        $status = $requests < 20 ? HealthStatus::Ok : match (true) {
            $percent >= $critical => HealthStatus::Critical,
            $percent >= $warning => HealthStatus::Warning,
            default => HealthStatus::Ok,
        };

        return new CheckResult('apache_requests', 'Requests', $status, $summary . ($problems === [] ? '' : ' ' . implode(' ', $problems)), (float) $requests, 'requests', ['status_5xx' => $failed, 'percent_5xx' => $percent]);
    }

    /**
     * @param array<string, string>|null $status
     * @param \Illuminate\Support\Collection<int, ApacheLogEntry> $recent
     */
    private function workersResult(?array $status, $recent): CheckResult
    {
        $limitReached = $recent->filter(fn (ApacheLogEntry $e) => str_contains($e->message, 'MaxRequestWorkers') || str_contains($e->message, 'MaxClients'));

        if ($status !== null && isset($status['BusyWorkers'], $status['Scoreboard']) && strlen($status['Scoreboard']) > 0) {
            $busy = (int) $status['BusyWorkers'];
            $slots = strlen($status['Scoreboard']);
            $percent = round($busy / $slots * 100, 1);
            $level = match (true) {
                $percent >= $this->settings->integer(SettingsService::APACHE_WORKERS_CRITICAL_PERCENT) => HealthStatus::Critical,
                $percent >= $this->settings->integer(SettingsService::APACHE_WORKERS_WARNING_PERCENT) || $limitReached->isNotEmpty() => HealthStatus::Warning,
                default => HealthStatus::Ok,
            };

            return new CheckResult('apache_workers', 'Workers', $level, "$busy of $slots workers busy ($percent%)." . ($limitReached->isNotEmpty() ? ' The error log says the worker limit was reached in the last hour.' : ''), $percent, '%', ['busy' => $busy, 'slots' => $slots, 'idle' => isset($status['IdleWorkers']) ? (int) $status['IdleWorkers'] : null]);
        }

        if ($limitReached->isNotEmpty()) {
            return new CheckResult('apache_workers', 'Workers', HealthStatus::Warning, 'All workers were busy in the last hour: ' . mb_substr((string) ($limitReached->last()->message ?? ''), 0, 200));
        }

        return new CheckResult('apache_workers', 'Workers', HealthStatus::Ok, 'Worker limit not reached in the last hour (from the error log; mod_status not available for live figures).');
    }

    /**
     * What's new in a log: from the file, or from the container's output when the log goes there.
     *
     * @param array<string, mixed> $state
     *
     * @throws ServerConnectionException
     */
    private function readLog(SSH2 $connection, string $path, array &$state): string
    {
        $stream = $this->streams[$path] ?? null;

        if ($stream === 'special') {
            throw new ServerConnectionException("$path isn't a regular file");
        }

        if ($stream !== null && $this->shell instanceof ContainerShell) {
            return $this->shell->readStream($connection, $stream, $path, $state);
        }

        return $this->remote()->readNew($connection, $path, $state);
    }

    private function readProblem(string $path, ServerConnectionException $e): string
    {
        $message = trim((string) preg_replace('/^The command exited with status \d+: /', '', $e->getMessage()));

        return "Couldn't read $path" . (str_contains($message, 'ermission denied') ? ': the SSH user needs read access (e.g. the adm group).' : ": $message.");
    }

    private function prune(): void
    {
        $cutoff = Carbon::instance($this->clock->now())->subDays(HealthCheckService::RETENTION_DAYS);
        ApacheLogEntry::query()->where('logged_at', '<', $cutoff)->delete();
        ApacheTraffic::query()->where('bucket_at', '<', $cutoff)->delete();
    }

    private function remote(): RemoteLogs
    {
        return $this->remote ??= new RemoteLogs($this->ssh);
    }
}
