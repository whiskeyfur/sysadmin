<?php

namespace App\Services;

use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use Carbon\Carbon;

/**
 * SSH reports: disk, load and memory over time for one server, read from
 * the stored health check runs (kept HealthCheckService::RETENTION_DAYS).
 */
class SshReportService extends HistoryReport
{
    /**
     * Chart series and table rows for a server over a period, oldest first.
     *
     * Series are lists of [unix time, value]; a value missing from a run
     * (e.g. no swap) leaves a gap rather than a zero.
     *
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     disk: array<string, list<array{0: int, 1: float}>>,
     *     load: array<string, list<array{0: int, 1: float}>>,
     *     memory: array<string, list<array{0: int, 1: float}>>,
     *     rows: list<array<string, mixed>>,
     *     bucket_minutes: int|null
     * }
     *
     * Each row has time (Carbon), disk and disk_mount (the fullest filesystem),
     * load1/load5/load15, cores, per_core, memory and swap; null when not
     * measured. bucket_minutes is set when runs were averaged into buckets
     * of that width.
     */
    public function report(Server $server, string $range = self::DEFAULT_RANGE): array
    {
        [$from, $to] = $this->window($range);

        $checks = StoredCheck::query()
            ->where('server_id', $server->id)
            ->whereIn('check_key', ['disk', 'load', 'memory'])
            ->where('checked_at', '>=', $from)
            ->get()
            ->sortBy(['checked_at', 'id']);

        $disk = [];
        $load = ['1 min' => [], '5 min' => [], '15 min' => []];
        $memory = ['Memory' => [], 'Swap' => []];
        $rows = [];

        foreach ($checks as $check) {
            $time = (int) $check->checked_at->getTimestamp();
            $row = $rows[$time] ?? ['time' => $check->checked_at, 'disk' => null, 'disk_mount' => null, 'load1' => null, 'load5' => null, 'load15' => null, 'cores' => null, 'per_core' => null, 'memory' => null, 'swap' => null];
            $details = $check->details ?? [];

            switch ($check->check_key) {
                case 'disk':
                    foreach ($details['mounts'] ?? [] as $mount => $usage) {
                        if (isset($usage['used_percent'])) {
                            $disk[(string) $mount][] = [$time, (float) $usage['used_percent']];

                            if ($row['disk'] === null || $usage['used_percent'] > $row['disk']) {
                                [$row['disk'], $row['disk_mount']] = [(float) $usage['used_percent'], (string) $mount];
                            }
                        }
                    }

                    break;

                case 'load':
                    foreach (['load1' => '1 min', 'load5' => '5 min', 'load15' => '15 min'] as $field => $series) {
                        if (isset($details[$field])) {
                            $load[$series][] = [$time, (float) $details[$field]];
                            $row[$field] = (float) $details[$field];
                        }
                    }

                    $row['cores'] = isset($details['cores']) ? (int) $details['cores'] : null;
                    $row['per_core'] = $check->value;

                    break;

                case 'memory':
                    if ($check->value !== null) {
                        $memory['Memory'][] = [$time, $check->value];
                        $row['memory'] = $check->value;
                    }

                    if (isset($details['swap_percent'])) {
                        $memory['Swap'][] = [$time, (float) $details['swap_percent']];
                        $row['swap'] = (float) $details['swap_percent'];
                    }

                    break;
            }

            $rows[$time] = $row;
        }

        ksort($disk);
        $load = array_filter($load);
        $memory = array_filter($memory);
        $bucket = null;

        if (count($rows) > self::MAX_POINTS) {
            $bucket = $this->bucketMinutes($to->getTimestamp() - $from->getTimestamp());
            $seconds = $bucket * 60;
            $disk = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $disk);
            $load = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $load);
            $memory = array_map(fn (array $points) => $this->averageSeries($points, $seconds), $memory);
            $rows = $this->averageRows($rows, $seconds, ['disk', 'load1', 'load5', 'load15', 'per_core', 'memory', 'swap'], ['cores'], function (array $group, array $row) {
                $fullest = collect($group)->filter(fn ($r) => $r['disk'] !== null)->sortByDesc('disk')->first();
                $row['disk_mount'] = $fullest['disk_mount'] ?? null;

                return $row;
            });
        }

        return [
            'from' => $from,
            'to' => $to,
            'disk' => $disk,
            'load' => $load,
            'memory' => $memory,
            'rows' => array_values($rows),
            'bucket_minutes' => $bucket,
        ];
    }
}
