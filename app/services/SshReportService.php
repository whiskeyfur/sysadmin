<?php

namespace App\Services;

use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Psr\Clock\ClockInterface;

/**
 * SSH reports: disk, load and memory over time for one server, read from
 * the stored health check runs (kept HealthCheckService::RETENTION_DAYS).
 */
class SshReportService
{
    /**
     * Report periods: key => [label, hours].
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const RANGES = [
        '24h' => ['Last 24 hours', 24],
        '7d' => ['Last 7 days', 24 * 7],
        '30d' => ['Last 30 days', 24 * 30],
    ];

    public const DEFAULT_RANGE = '7d';

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

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
     *     rows: list<array{time: Carbon, disk: ?float, disk_mount: ?string, load1: ?float, load5: ?float, load15: ?float, cores: ?int, per_core: ?float, memory: ?float, swap: ?float}>
     * }
     */
    public function report(Server $server, string $range = self::DEFAULT_RANGE): array
    {
        $hours = (self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE])[1];
        $to = Carbon::instance($this->clock->now());
        $from = $to->copy()->subHours($hours);

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
            $time = $check->checked_at->getTimestamp();
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

        return [
            'from' => $from,
            'to' => $to,
            'disk' => $disk,
            'load' => array_filter($load),
            'memory' => array_filter($memory),
            'rows' => array_values($rows),
        ];
    }
}
