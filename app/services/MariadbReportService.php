<?php

namespace App\Services;

use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use Carbon\Carbon;

/**
 * MariaDB reports: connections, InnoDB buffer pool hit ratio, replication
 * lag, crashed tables and uptime over time for one server, read from the
 * stored health check runs (kept HealthCheckService::RETENTION_DAYS).
 */
class MariadbReportService extends HistoryReport
{
    private const KEYS = ['server_status', 'connections', 'crashed_tables', 'replication', 'innodb_buffer_pool'];

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
     *     rows: list<array<string, mixed>>,
     *     bucket_minutes: int|null
     * }
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
        $rows = [];

        foreach ($checks as $check) {
            $time = (int) $check->checked_at->getTimestamp();
            $row = $rows[$time] ?? ['time' => $check->checked_at, 'connected' => null, 'max_connections' => null, 'peak' => null, 'connections' => null, 'buffer_pool' => null, 'lag' => null, 'crashed' => null, 'uptime_days' => null];
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
            }

            $rows[$time] = $row;
        }

        $bucket = null;

        if (count($rows) > self::MAX_POINTS) {
            $bucket = $this->bucketMinutes($to->getTimestamp() - $from->getTimestamp());
            $seconds = $bucket * 60;
            foreach ($series as $key => $points) {
                $series[$key] = $this->averageSeries($points, $seconds, worst: $key === 'crashed');
            }

            // Crashed tables: the worst in the bucket, so a crash isn't averaged away.
            $rows = $this->averageRows($rows, $seconds, ['connected', 'connections', 'buffer_pool', 'lag', 'uptime_days'], ['max_connections', 'peak'], function (array $group, array $row) {
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
            'rows' => array_values($rows),
            'bucket_minutes' => $bucket,
        ];
    }
}
