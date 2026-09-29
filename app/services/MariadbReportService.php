<?php

namespace App\Services;

use App\Models\HealthCheck as StoredCheck;
use App\Models\MariadbLogEntry;
use App\Models\Server;
use Carbon\Carbon;

/**
 * MariaDB reports: connections, InnoDB buffer pool hit ratio, replication
 * lag, crashed tables and uptime over time for one server, read from the
 * stored health check runs (kept HealthCheckService::RETENTION_DAYS).
 */
class MariadbReportService extends HistoryReport
{
    private const KEYS = ['server_status', 'connections', 'crashed_tables', 'replication', 'innodb_buffer_pool', 'disk_space', 'database_size', 'file_io'];

    /**
     * Databases charted by size (the largest); the table has the total.
     */
    private const MAX_DATABASES = 6;

    /**
     * Log levels charted, and their series names (notes are too many to chart).
     */
    private const LOG_LEVELS = ['crash' => 'Crashes', 'error' => 'Errors', 'warning' => 'Warnings', 'slow' => 'Slow queries'];

    /**
     * Interval for counting log entries, per report period.
     */
    private const LOG_BUCKET_MINUTES = ['24h' => 60, '7d' => 360, '30d' => 1440];

    /**
     * Chart series and table rows for a server over a period, oldest first.
     * Series are lists of [unix time, value]; a value a run didn't measure
     * (e.g. lag on a server that isn't a replica) leaves a gap, not a zero.
     *
     * Each row has time (Carbon), connected, max_connections, peak,
     * connections (% of max), buffer_pool (% of reads from memory), lag
     * (seconds, replicas only), crashed (tables) and uptime_days; null when
     * not measured. bucket_minutes is set when runs were averaged into
     * buckets of that width.
     *
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     connections: array<string, list<array{0: int, 1: float}>>,
     *     buffer_pool: array<string, list<array{0: int, 1: float}>>,
     *     lag: array<string, list<array{0: int, 1: float}>>,
     *     crashed: array<string, list<array{0: int, 1: float}>>,
     *     uptime: array<string, list<array{0: int, 1: float}>>,
     *     disk: array<string, list<array{0: int, 1: float}>>,
     *     sizes: array<string, list<array{0: int, 1: float}>>,
     *     io: array<string, list<array{0: int, 1: float}>>,
     *     rows: list<array<string, mixed>>,
     *     bucket_minutes: int|null,
     *     log: list<MariadbLogEntry>,
     *     log_counts: array<string, list<array{0: int, 1: float}>>,
     *     log_bucket_minutes: int
     * }
     *
     * log is the imported log entries in the period (newest first, up to
     * 500); log_counts counts them per level in log_bucket_minutes intervals.
     */
    public function report(Server $server, string $range = self::DEFAULT_RANGE): array
    {
        [$from, $to] = $this->window($range);

        $checks = StoredCheck::query()
            ->where('server_id', $server->id)
            ->whereIn('check_key', self::KEYS)
            ->where('checked_at', '>=', $from)
            ->get()
            ->sortBy(['checked_at', 'id']);

        $series = ['connections' => [], 'buffer_pool' => [], 'lag' => [], 'crashed' => [], 'uptime' => []];
        // Read through MariaDB (no SSH needed): disk used % per filesystem, MB per database, and I/O in MB per hour.
        $disk = [];
        $sizes = [];
        $io = ['Read' => [], 'Written' => []];
        $previousIo = null;
        $rows = [];

        foreach ($checks as $check) {
            $time = (int) $check->checked_at->getTimestamp();
            $row = $rows[$time] ?? ['time' => $check->checked_at, 'connected' => null, 'max_connections' => null, 'peak' => null, 'connections' => null, 'buffer_pool' => null, 'lag' => null, 'crashed' => null, 'uptime_days' => null, 'disk' => null, 'db_size_mb' => null, 'io_read' => null, 'io_write' => null];
            $details = $check->details ?? [];
            $value = $check->value;

            switch ($check->check_key) {
                case 'connections':
                    if ($value !== null) {
                        $series['connections'][] = [$time, $value];
                        $row['connections'] = $value;
                        $row['connected'] = isset($details['connected']) ? (int) $details['connected'] : null;
                        $row['max_connections'] = isset($details['max']) ? (int) $details['max'] : null;
                        $row['peak'] = isset($details['peak']) ? (int) $details['peak'] : null;
                    }

                    break;

                case 'innodb_buffer_pool':
                    // Only runs with enough reads to judge have a ratio.
                    if ($value !== null) {
                        $series['buffer_pool'][] = [$time, $value];
                        $row['buffer_pool'] = $value;
                    }

                    break;

                case 'replication':
                    // A value only on replicas that are running.
                    if ($value !== null && ($details['replicas'] ?? 0) > 0) {
                        $series['lag'][] = [$time, $value];
                        $row['lag'] = $value;
                    }

                    break;

                case 'crashed_tables':
                    if ($value !== null) {
                        $series['crashed'][] = [$time, $value];
                        $row['crashed'] = $value;
                    }

                    break;

                case 'server_status':
                    if ($value !== null) {
                        $days = round($value / 86400, 2);
                        $series['uptime'][] = [$time, $days];
                        $row['uptime_days'] = $days;
                    }

                    break;

                case 'disk_space':
                    foreach ($details['mounts'] ?? [] as $mount => $usage) {
                        if (isset($usage['used_percent'])) {
                            $disk[(string) $mount][] = [$time, (float) $usage['used_percent']];
                            $row['disk'] = max($row['disk'] ?? 0.0, (float) $usage['used_percent']);
                        }
                    }

                    break;

                case 'database_size':
                    if ($value !== null && isset($details['databases'])) {
                        $row['db_size_mb'] = $value;

                        foreach ($details['databases'] as $name => $bytes) {
                            $sizes[(string) $name][] = [$time, round($bytes / 1048576, 1)];
                        }
                    }

                    break;

                case 'file_io':
                    if (!isset($details['bytes_read'], $details['bytes_written'])) {
                        break;
                    }

                    $current = [$time, (int) $details['bytes_read'], (int) $details['bytes_written']];

                    // Counters run since the server started: a drop means it restarted, so no rate across it.
                    if ($previousIo !== null && $time > $previousIo[0] && $current[1] >= $previousIo[1] && $current[2] >= $previousIo[2]) {
                        $hours = ($time - $previousIo[0]) / 3600;
                        $row['io_read'] = round(($current[1] - $previousIo[1]) / 1048576 / $hours, 2);
                        $row['io_write'] = round(($current[2] - $previousIo[2]) / 1048576 / $hours, 2);
                        $io['Read'][] = [$time, $row['io_read']];
                        $io['Written'][] = [$time, $row['io_write']];
                    }

                    $previousIo = $current;

                    break;
            }

            $rows[$time] = $row;
        }

        $bucket = null;

        if (count($rows) > self::MAX_POINTS) {
            $bucket = $this->bucketMinutes($to->getTimestamp() - $from->getTimestamp());
            $seconds = $bucket * 60;
            foreach ($series as $key => $points) {
                $series[$key] = $this->averageSeries($points, $seconds, $key === 'crashed' ? 'max' : 'average');
            }

            $disk = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $disk);
            $sizes = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $sizes);
            $io = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $io);

            // Crashed tables: the worst in the bucket, so a crash isn't averaged away.
            $rows = $this->averageRows($rows, $seconds, ['connected', 'connections', 'buffer_pool', 'lag', 'uptime_days', 'disk', 'db_size_mb', 'io_read', 'io_write'], ['max_connections', 'peak'], function (array $group, array $row) {
                $crashed = array_filter(array_column($group, 'crashed'), fn ($v) => $v !== null);
                $row['crashed'] = $crashed === [] ? null : max($crashed);

                return $row;
            });
        }

        $names = [
            'connections' => '% of max_connections',
            'buffer_pool' => 'Reads from memory',
            'lag' => 'Seconds behind the source',
            'crashed' => 'Crashed tables',
            'uptime' => 'Uptime (days)',
        ];
        $named = [];

        foreach ($series as $key => $points) {
            $named[$key] = $points === [] ? [] : [$names[$key] => $points];
        }

        return [
            'from' => $from,
            'to' => $to,
            'connections' => $named['connections'],
            'buffer_pool' => $named['buffer_pool'],
            'lag' => $named['lag'],
            'crashed' => $named['crashed'],
            'uptime' => $named['uptime'],
            'disk' => $this->sorted($disk),
            'sizes' => $this->largest($sizes),
            'io' => array_filter($io),
            'rows' => array_values($rows),
            'bucket_minutes' => $bucket,
            'log' => $this->logEntries($server, $from),
            'log_counts' => $this->logCounts($server, $from, $to, $logBucket = self::LOG_BUCKET_MINUTES[$range] ?? self::LOG_BUCKET_MINUTES[self::DEFAULT_RANGE]),
            'log_bucket_minutes' => $logBucket,
        ];
    }

    /**
     * @param array<string, list<array{0: int, 1: float}>> $series
     * @return array<string, list<array{0: int, 1: float}>>
     */
    private function sorted(array $series): array
    {
        ksort($series, SORT_NATURAL);

        return $series;
    }

    /**
     * The largest databases (by their latest size), largest first.
     *
     * @param array<string, list<array{0: int, 1: float}>> $sizes
     * @return array<string, list<array{0: int, 1: float}>>
     */
    private function largest(array $sizes): array
    {
        uasort($sizes, fn (array $a, array $b) => end($b)[1] <=> end($a)[1]);

        return array_slice($sizes, 0, self::MAX_DATABASES, true);
    }

    /**
     * Imported log entries in the period, newest first.
     *
     * @return list<MariadbLogEntry>
     */
    private function logEntries(Server $server, Carbon $from, int $limit = 500): array
    {
        /** @var list<MariadbLogEntry> $entries */
        $entries = MariadbLogEntry::query()->where('server_id', $server->id)->where('logged_at', '>=', $from)
            ->orderByDesc('logged_at')->orderByDesc('id')->limit($limit)->get()->all();

        return $entries;
    }

    /**
     * Log entries per level and interval, zero-filled so quiet periods show as zero.
     *
     * @return array<string, list<array{0: int, 1: float}>> series name => points; only levels that occur
     */
    private function logCounts(Server $server, Carbon $from, Carbon $to, int $bucketMinutes): array
    {
        $seconds = $bucketMinutes * 60;
        $start = intdiv($from->getTimestamp(), $seconds) * $seconds;
        $counts = [];

        foreach (MariadbLogEntry::query()->where('server_id', $server->id)->where('logged_at', '>=', $from)->get(['level', 'logged_at']) as $entry) {
            $bucket = intdiv($entry->logged_at->getTimestamp(), $seconds) * $seconds;
            $counts[$entry->level][$bucket] = ($counts[$entry->level][$bucket] ?? 0) + 1;
        }

        $series = [];

        foreach (self::LOG_LEVELS as $level => $name) {
            if (!isset($counts[$level])) {
                continue;
            }

            for ($time = $start; $time <= $to->getTimestamp(); $time += $seconds) {
                $series[$name][] = [$time, (float) ($counts[$level][$time] ?? 0)];
            }
        }

        return $series;
    }
}
