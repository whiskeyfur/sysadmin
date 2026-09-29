<?php

namespace App\Services;

use App\Enums\ServerPlatform;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbLogEntry;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DateTimeZone;
use DomainException;
use PDO;
use phpseclib3\Net\SSH2;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Imports a MariaDB server's logs into the app's history, over one SSH
 * login (for MariaDB servers that also have SSH set up).
 *
 * Nothing about where the logs are is assumed:
 * 1. The option files are the ones the server reads, as `my_print_defaults
 *    --help` lists them (only if that tool is missing: the documented
 *    /etc/my.cnf and /etc/mysql/my.cnf). !include and !includedir are
 *    followed, like MariaDB does.
 * 2. log_error, slow_query_log(_file), log_output and datadir come from the
 *    server sections of those files; relative paths are relative to datadir.
 *    What the files don't set (e.g. datadir left at its compiled-in default,
 *    or the hostname for default file names) comes from the running server's
 *    own variables when its MariaDB login works.
 * 3. No log_error in the files means MariaDB logs to stderr: under systemd
 *    that's the journal, read by process name (mariadbd, mysqld).
 *
 * Files are read from the end (MAX_BYTES). Entries are kept
 * HealthCheckService::RETENTION_DAYS; importing again adds only new lines.
 */
class MariadbLogService
{
    public const MAX_BYTES = 5_000_000;

    public const MAX_JOURNAL_LINES = 20000;

    private const BATCH = 500;

    private const MAX_OPTION_FILES = 50;

    private const DOCUMENTED_OPTION_FILES = ['/etc/my.cnf', '/etc/mysql/my.cnf'];

    private ?RemoteLogs $remote = null;

    public function __construct(
        private readonly SshService $ssh = new SshService(),
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly MariadbConfigParser $config = new MariadbConfigParser(),
        private readonly MariadbLogParser $parser = new MariadbLogParser(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function canImport(Server $server): bool
    {
        return $server->mysql_enabled && $server->sshReady();
    }

    /**
     * @return array{
     *     configuration: list<string>,
     *     sources: list<array{label: string, where: string, read: int, imported: int, problem: ?string}>
     * } what was found where, and per log source what was read and imported
     *
     * @throws DomainException when the server can't be imported from (not set up, or not Linux/Unix)
     * @throws ServerConnectionException when SSH fails
     */
    public function import(User $admin, Server $server): array
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can import logs.');
        }

        return $this->importNow($server);
    }

    /**
     * Import without a user: the scheduler (ScheduledCheckService). The
     * outcome is kept in servers.log_import_message for the report, since
     * nobody sees a scheduled import happen. $useDatabase false skips the
     * MariaDB login (when it's failing, so failed attempts don't pile up).
     *
     * @return array{
     *     configuration: list<string>,
     *     sources: list<array{label: string, where: string, read: int, imported: int, problem: ?string}>
     * }
     *
     * @throws DomainException when the server can't be imported from
     * @throws ServerConnectionException when SSH fails
     */
    public function importNow(Server $server, bool $useDatabase = true): array
    {
        if (!$this->canImport($server)) {
            throw new DomainException("{$server->name} needs both MariaDB monitoring and SSH set up to import its log.");
        }

        // The running server's variables only fill gaps in the option files; skip them when its login is failing.
        $variables = $useDatabase ? $this->variables($server) : [];
        $connection = $this->ssh->connect($server);

        try {
            if ($this->ssh->platformOf($connection) === ServerPlatform::Windows) {
                throw new DomainException('Importing the log works on Linux/Unix servers only so far.');
            }

            $zone = $this->zone($connection);
            [$settings, $configuration] = $this->readConfiguration($connection);
            $sources = $this->locate($connection, $settings, $variables, $configuration);
            $configuration[] = 'Server timezone: UTC' . $zone->getName() . '.';
            $results = [];
            $state = $server->log_import_state ?? [];

            foreach ($sources as $source) {
                $results[] = $this->importSource($connection, $server, $source, $zone, $state);
            }
        } finally {
            $connection->disconnect();
        }

        $imported = array_sum(array_column($results, 'imported'));
        $problems = array_filter(array_map(fn (array $r) => $r['problem'] === null ? null : "{$r['label']}: {$r['problem']}", $results));
        $server->log_imported_at = Carbon::instance($this->clock->now());
        $server->log_import_state = $state;
        $server->log_import_message = mb_substr(implode(' ', ["$imported new entr" . ($imported === 1 ? 'y' : 'ies') . '.', ...$problems]), 0, 2000);
        $server->save();
        $this->prune();

        return ['configuration' => $configuration, 'sources' => $results];
    }

    /**
     * The server's options, from its option files, in the order MariaDB
     * applies them (later wins), plus a note per file read.
     *
     * @return array{0: array<string, array{value: string|null, file: string}>, 1: list<string>}
     */
    public function readConfiguration(SSH2 $connection): array
    {
        $files = $this->optionFiles($connection);
        $notes = ['Option files, in the order MariaDB reads them: ' . implode(', ', $files) . '.'];
        $settings = [];
        $seen = [];

        foreach ($files as $file) {
            $this->readOptionFile($connection, $file, $settings, $notes, $seen);
        }

        return [$settings, $notes];
    }

    /**
     * Where the logs are, from the settings (and the running server's
     * variables for what the files don't say).
     *
     * @param array<string, array{value: string|null, file: string}> $settings
     * @param array<string, string> $variables
     * @param list<string> $notes
     * @return list<array{type: 'error'|'slow'|'journal', label: string, path: ?string, since: ?Carbon}>
     */
    public function locate(SSH2 $connection, array $settings, array $variables, array &$notes): array
    {
        $datadir = $settings['datadir']['value'] ?? ($variables['datadir'] ?? null);
        $notes[] = 'datadir: ' . ($datadir === null
            ? 'not set in the option files, and not available from the running server (its MariaDB login failed); only needed for relative log paths'
            : $datadir . (isset($settings['datadir']) ? " (from {$settings['datadir']['file']})" : ' (the running server)')) . '.';
        $hostname = $variables['hostname'] ?? null;
        $resolve = function (?string $path, string $defaultSuffix) use ($datadir, &$hostname, $connection): ?string {
            if ($path === null || $path === '') {
                $hostname ??= trim($this->run($connection, 'hostname')) ?: null;
                $path = $hostname === null ? null : $hostname . $defaultSuffix;
            }

            if ($path === null) {
                return null;
            }

            return str_starts_with($path, '/') ? $path : ($datadir === null ? null : rtrim($datadir, '/') . '/' . $path);
        };

        $sources = [];

        if (array_key_exists('log_error', $settings)) {
            $path = $resolve($settings['log_error']['value'], '.err');
            $notes[] = 'Error log: ' . ($path ?? 'unknown (datadir unknown)') . " (log_error in {$settings['log_error']['file']}).";

            if ($path !== null) {
                $sources[] = ['type' => 'error', 'label' => 'Error log', 'path' => $path, 'since' => null];
            }
        } else {
            $notes[] = 'Error log: no log_error in the option files, so MariaDB writes errors to stderr; reading the systemd journal.';
            $sources[] = ['type' => 'journal', 'label' => 'Error log', 'path' => null, 'since' => null];
        }

        $slowSetting = $settings['slow_query_log'] ?? $settings['log_slow_queries'] ?? null;
        $slowOn = $slowSetting !== null ? $this->isOn($slowSetting['value']) : $this->isOn($variables['slow_query_log'] ?? 'OFF');
        $output = strtoupper((string) ($settings['log_output']['value'] ?? ($variables['log_output'] ?? 'FILE')));

        if (!$slowOn) {
            $notes[] = 'Slow query log: off.';
        } elseif (!str_contains($output, 'FILE')) {
            $notes[] = "Slow query log: written to a table (log_output=$output), not a file; not imported.";
        } else {
            $fileSetting = $settings['slow_query_log_file'] ?? $settings['log_slow_query_file'] ?? null;
            $path = $resolve($fileSetting['value'] ?? ($variables['slow_query_log_file'] ?? null), '-slow.log');
            $notes[] = 'Slow query log: ' . ($path ?? 'unknown') . ($fileSetting ? " (from {$fileSetting['file']})" : '') . '.';

            if ($path !== null) {
                $sources[] = ['type' => 'slow', 'label' => 'Slow query log', 'path' => $path, 'since' => null];
            }
        }

        return $sources;
    }

    /**
     * The option files the server reads, in order.
     *
     * @return list<string>
     */
    private function optionFiles(SSH2 $connection): array
    {
        // "Default options are read from the following files in the given order:\n/etc/my.cnf /etc/mysql/my.cnf ~/.my.cnf"
        $help = $this->run($connection, 'my_print_defaults --help 2>/dev/null || mariadbd --verbose --help 2>/dev/null | head -n 40');

        if (preg_match('/following files in the given order:\s*\R(.+)/', $help, $m) === 1) {
            // ~/.my.cnf is the SSH user's own file, not the server's.
            $files = array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: [], fn (string $f) => str_starts_with($f, '/')));

            if ($files !== []) {
                return $files;
            }
        }

        return self::DOCUMENTED_OPTION_FILES;
    }

    /**
     * @param array<string, array{value: string|null, file: string}> $settings
     * @param list<string> $notes
     * @param array<string, true> $seen
     */
    private function readOptionFile(SSH2 $connection, string $file, array &$settings, array &$notes, array &$seen, int $depth = 0): void
    {
        if (isset($seen[$file]) || count($seen) >= self::MAX_OPTION_FILES || $depth > 10) {
            return;
        }

        $seen[$file] = true;

        try {
            $text = $this->ssh->exec($connection, 'cat -- ' . escapeshellarg($file) . ' 2>&1');
        } catch (ServerConnectionException $e) {
            if (!str_contains($e->getMessage(), 'No such file')) {
                $notes[] = "Couldn't read $file: " . $this->shortError($e) . '.';
            }

            return;
        }

        $parsed = $this->config->parse($text);

        foreach ($parsed['options'] as $option) {
            if ($this->config->isServerSection($option['section'])) {
                $settings[$option['name']] = ['value' => $option['value'], 'file' => $file];
            }
        }

        foreach ($parsed['includes'] as $include) {
            $path = str_starts_with($include['path'], '/') ? $include['path'] : dirname($file) . '/' . $include['path'];

            if ($include['type'] === 'file') {
                $this->readOptionFile($connection, $path, $settings, $notes, $seen, $depth + 1);

                continue;
            }

            // !includedir: the directory's .cnf files, in name order.
            $names = preg_split('/\R/', trim($this->run($connection, 'ls -1 -- ' . escapeshellarg($path) . ' 2>/dev/null'))) ?: [];
            $names = array_values(array_filter($names, fn (string $n) => str_ends_with($n, '.cnf')));
            sort($names, SORT_STRING);

            foreach ($names as $name) {
                $this->readOptionFile($connection, rtrim($path, '/') . '/' . $name, $settings, $notes, $seen, $depth + 1);
            }
        }
    }

    /**
     * @param array{type: 'error'|'slow'|'journal', label: string, path: ?string, since: ?Carbon} $source
     * @param array<string, array{inode: string, offset: int}> $state how far each file was read before; updated
     * @return array{label: string, where: string, read: int, imported: int, problem: ?string}
     */
    private function importSource(SSH2 $connection, Server $server, array $source, DateTimeZone $zone, array &$state): array
    {
        $cutoff = Carbon::instance($this->clock->now())->subDays(HealthCheckService::RETENTION_DAYS);
        $where = $source['path'] ?? 'the systemd journal, processes mariadbd and mysqld';

        try {
            $text = $source['type'] === 'journal'
                ? $this->ssh->exec($connection, sprintf(
                    'journalctl _COMM=mariadbd + _COMM=mysqld -o short-iso --no-pager -q --since %s -n %d 2>&1',
                    escapeshellarg('@' . max($cutoff->getTimestamp(), (int) $server->log_imported_at?->copy()->subHour()->getTimestamp())),
                    self::MAX_JOURNAL_LINES,
                ))
                : $this->readNew($connection, (string) $source['path'], $state);
        } catch (ServerConnectionException $e) {
            $hint = str_contains($e->getMessage(), 'ermission denied') || str_contains($e->getMessage(), 'not seeing messages')
                ? ($source['type'] === 'journal'
                    ? ' The SSH user needs to be in the systemd-journal (or adm) group to read the journal.'
                    : " The SSH user needs read access to it (e.g. membership of the file's group, often mysql or adm).")
                : '';

            return ['label' => $source['label'], 'where' => $where, 'read' => 0, 'imported' => 0, 'problem' => $this->shortError($e) . '.' . $hint];
        }

        // journalctl doesn't fail without access to the system journal: it shows only the user's own lines, with a hint.
        $problem = $source['type'] === 'journal' && str_contains($text, 'not seeing messages from other users')
            ? 'Only part of the journal is readable: the SSH user needs to be in the systemd-journal (or adm) group.'
            : null;
        // Streamed and stored in batches: a whole log parsed at once can exceed PHP's memory limit.
        $entries = $source['type'] === 'slow' ? $this->parser->slowEntries($text, $zone) : $this->parser->errorEntries($text, $zone);
        $type = $source['type'] === 'journal' ? 'journal' : $source['type'];
        $read = 0;
        $imported = 0;
        $batch = [];
        $repeats = ['second' => null, 'counts' => []];

        foreach ($entries as $entry) {
            if ($entry['time']->lessThan($cutoff)) {
                continue;
            }

            $read++;
            $batch[] = $entry;

            if (count($batch) >= self::BATCH) {
                $imported += $this->store($server, $type, $batch, $repeats);
                $batch = [];
            }
        }

        $imported += $this->store($server, $type, $batch, $repeats);

        return [
            'label' => $source['label'],
            'where' => $where,
            'read' => $read,
            'imported' => $imported,
            'problem' => $problem,
        ];
    }

    /**
     * What's new in a log file since the last import: from the byte the last
     * import stopped at, or (new, rotated or truncated file) the last
     * MAX_BYTES. Stops at the last complete line, so a line being written is
     * read whole next time.
     *
     * @param array<string, array{inode: string, offset: int}> $state
     *
     * @throws ServerConnectionException
     */
    private function readNew(SSH2 $connection, string $path, array &$state): string
    {
        return $this->remote()->readNew($connection, $path, $state);
    }

    /**
     * Store entries not imported before.
     *
     * @param list<array{time: Carbon, level: string, message: string}> $entries
     * @param array{second: int|null, counts: array<string, int>} $repeats identical messages in the current second, across batches
     * @return int how many were new
     */
    private function store(Server $server, string $source, array $entries, array &$repeats): int
    {
        return $this->remote()->store(MariadbLogEntry::class, $server, $source, $entries, $repeats);
    }

    /**
     * The running server's variables that help locate logs; empty when its
     * MariaDB login fails (the option files are enough on their own).
     *
     * @return array<string, string>
     */
    protected function variables(Server $server): array
    {
        try {
            $pdo = $this->mysql->connect($server);
            $statement = $pdo->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('datadir', 'hostname', 'log_error', 'slow_query_log', 'slow_query_log_file', 'log_output')");

            return $statement === false ? [] : array_map('strval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return [];
        }
    }

    private function zone(SSH2 $connection): DateTimeZone
    {
        return $this->remote()->zone($connection);
    }

    /**
     * Run a command whose failure isn't fatal: output, or '' on failure.
     */
    private function run(SSH2 $connection, string $command): string
    {
        return $this->remote()->run($connection, $command);
    }

    private function remote(): RemoteLogs
    {
        return $this->remote ??= new RemoteLogs($this->ssh, self::MAX_BYTES);
    }

    private function isOn(?string $value): bool
    {
        return $value === null || in_array(strtoupper(trim($value)), ['1', 'ON', 'TRUE', 'YES'], true);
    }

    private function shortError(ServerConnectionException $e): string
    {
        return mb_substr(trim((string) preg_replace('/^The command exited with status \d+: /', '', $e->getMessage())), 0, 300);
    }

    private function prune(): void
    {
        MariadbLogEntry::query()->where('logged_at', '<', Carbon::instance($this->clock->now())->subDays(HealthCheckService::RETENTION_DAYS))->delete();
    }
}
