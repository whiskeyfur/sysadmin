<?php

namespace App\Services;

use App\Models\ApacheAccessEntry;
use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\ApacheVhost;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use Carbon\Carbon;

/**
 * Apache reports: requests, server errors and traffic from the access logs
 * (apache_traffic, summed over all of the server's access logs), busy
 * workers from mod_status runs, and error log entries. For one virtual
 * host, the same from the logs it writes to (vhostLogs()).
 */
class ApacheReportService extends HistoryReport
{
    public const MAX_ENTRIES = 500;

    private const LOG_LEVELS = ['crash' => 'Crashes', 'error' => 'Errors', 'warning' => 'Warnings'];

    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     bucket_minutes: int,
     *     access: list<ApacheAccessEntry>,
     *     requests: array<string, list<array{0: int, 1: float}>>,
     *     traffic: array<string, list<array{0: int, 1: float}>>,
     *     workers: array<string, list<array{0: int, 1: float}>>,
     *     log_counts: array<string, list<array{0: int, 1: float}>>,
     *     rows: list<array{time: Carbon, requests: int, bytes: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int}>,
     *     log: list<ApacheLogEntry>
     * }
     *
     * requests and traffic are per minute, averaged over bucket_minutes
     * intervals (5 minutes, or longer for long periods); rows are the
     * intervals' totals, newest first.
     */
    public function report(Server $server, string $range = self::DEFAULT_RANGE, ?ApacheVhost $vhost = null): array
    {
        $logs = $vhost === null ? null : $this->vhostLogs($server, $vhost);
        [$from, $to] = $this->window($range);
        $span = $to->getTimestamp() - $from->getTimestamp();
        $bucket = $span / 300 > self::MAX_POINTS ? $this->bucketMinutes($span) : 5;
        $seconds = $bucket * 60;
        $intervals = [];

        $traffic = ApacheTraffic::query()->where('server_id', $server->id)->whereBetween('bucket_at', [$from, $to]);

        foreach (($logs === null ? $traffic : $traffic->whereIn('log', $logs['access']))->get() as $row) {
            $time = intdiv($row->bucket_at->getTimestamp(), $seconds) * $seconds;
            $totals = $intervals[$time] ?? ['requests' => 0, 'bytes' => 0, 'status_2xx' => 0, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0];

            foreach (array_keys($totals) as $field) {
                $totals[$field] += (int) $row->{$field};
            }

            $intervals[$time] = $totals;
        }

        ksort($intervals);

        // The logs cover the whole span: an interval without a line had no requests.
        if ($intervals !== []) {
            $empty = ['requests' => 0, 'bytes' => 0, 'status_2xx' => 0, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0];

            [$first, $last] = [array_key_first($intervals), array_key_last($intervals)];

            for ($time = $first; $time <= $last; $time += $seconds) {
                $intervals[$time] ??= $empty;
            }

            ksort($intervals);
        }

        $requests = ['Requests' => [], 'Client errors (4xx)' => [], 'Server errors (5xx)' => []];
        $traffic = ['MB served' => []];
        $rows = [];

        foreach ($intervals as $time => $totals) {
            $requests['Requests'][] = [$time, round($totals['requests'] / $bucket, 2)];
            $requests['Client errors (4xx)'][] = [$time, round($totals['status_4xx'] / $bucket, 2)];
            $requests['Server errors (5xx)'][] = [$time, round($totals['status_5xx'] / $bucket, 2)];
            $traffic['MB served'][] = [$time, round($totals['bytes'] / 1048576 / $bucket, 3)];
            $rows[] = ['time' => Carbon::createFromTimestamp($time)] + $totals;
        }

        $workers = $vhost !== null ? [] : StoredCheck::query()->where('server_id', $server->id)->where('check_key', 'apache_workers')->where('unit', '%')
            ->where('checked_at', '>=', $from)->where('checked_at', '<=', $to)->get()->sortBy('checked_at')
            ->map(fn (StoredCheck $c) => [(int) $c->checked_at->getTimestamp(), (float) $c->value])->values()->all();

        if (count($workers) > self::MAX_POINTS) {
            $workers = $this->averageSeries($workers, $this->bucketMinutes($span) * 60, 'max');
        }

        /** @var list<ApacheLogEntry> $log */
        $log = $this->entries($server, $logs)->whereBetween('logged_at', [$from, $to])
            ->orderByDesc('logged_at')->orderByDesc('id')->limit(self::MAX_ENTRIES)->get()->all();

        $access = ApacheAccessEntry::query()->where('server_id', $server->id)->whereBetween('requested_at', [$from, $to]);

        if ($logs !== null) {
            $access->whereIn('source', $logs['access']);
        }

        // A shared log (e.g. other_vhosts_access.log) holds other hosts' requests too: keep this one's.
        if ($vhost?->name !== null) {
            $access->where(fn ($q) => $q->whereNull('vhost')->orWhere('vhost', $vhost->name)->orWhere('vhost', 'like', addcslashes($vhost->name, '%_\\') . ':%'));
        }

        /** @var list<ApacheAccessEntry> $requestLog */
        $requestLog = $access->orderByDesc('requested_at')->orderByDesc('id')->limit(self::MAX_ENTRIES)->get()->all();

        return [
            'from' => $from,
            'to' => $to,
            'bucket_minutes' => $bucket,
            'access' => $requestLog,
            'requests' => array_filter($requests),
            'traffic' => array_filter($traffic),
            'workers' => $workers === [] ? [] : ['Busy workers' => $workers],
            'log_counts' => $this->logCounts($server, $logs, $from, $to, max(60, $bucket)),
            'rows' => array_reverse($rows),
            'log' => $log,
        ];
    }

    /**
     * The logs a virtual host writes to: its own, else the main server's
     * (from the last scan); shared lists those other hosts write to as well,
     * whose figures are in its report too.
     *
     * @return array{access: list<string>, error: list<string>, shared: list<string>}
     */
    public function vhostLogs(Server $server, ApacheVhost $vhost): array
    {
        $config = $server->apache_config ?? [];
        $main = fn (string $key) => array_values(array_map('strval', array_keys(array_filter($config[$key] ?? [], fn ($where) => in_array('main server', (array) $where, true)))));
        $access = $vhost->accessLogList() ?: $main('access_logs');
        $error = $vhost->error_log !== null ? [$vhost->error_log] : $main('error_logs');
        $shared = [];

        foreach (['access_logs' => $access, 'error_logs' => $error] as $key => $paths) {
            foreach ($paths as $path) {
                $writers = (array) ($config[$key][$path] ?? []);

                if (count($writers) > 1 || $vhost->accessLogList() === [] && $key === 'access_logs' || $vhost->error_log === null && $key === 'error_logs') {
                    $shared[] = $path;
                }
            }
        }

        return ['access' => $access, 'error' => $error, 'shared' => array_values(array_unique($shared))];
    }

    /**
     * @param array{access: list<string>, error: list<string>, shared: list<string>}|null $logs one vhost's, or null for all
     * @return \Illuminate\Database\Eloquent\Builder<ApacheLogEntry>
     */
    private function entries(Server $server, ?array $logs): \Illuminate\Database\Eloquent\Builder
    {
        $query = ApacheLogEntry::query()->where('server_id', $server->id);

        return $logs === null ? $query : $query->whereIn('source', $logs['error']);
    }

    /**
     * Error log entries per level and interval, zero-filled.
     *
     * @param array{access: list<string>, error: list<string>, shared: list<string>}|null $logs
     *
     * @return array<string, list<array{0: int, 1: float}>>
     */
    private function logCounts(Server $server, ?array $logs, Carbon $from, Carbon $to, int $minutes): array
    {
        $seconds = $minutes * 60;
        $counts = [];

        foreach ($this->entries($server, $logs)->whereBetween('logged_at', [$from, $to])->whereIn('level', array_keys(self::LOG_LEVELS))->get(['level', 'logged_at']) as $entry) {
            $bucket = intdiv($entry->logged_at->getTimestamp(), $seconds) * $seconds;
            $counts[$entry->level][$bucket] = ($counts[$entry->level][$bucket] ?? 0) + 1;
        }

        $series = [];

        foreach (self::LOG_LEVELS as $level => $name) {
            if (!isset($counts[$level])) {
                continue;
            }

            for ($time = intdiv($from->getTimestamp(), $seconds) * $seconds; $time <= $to->getTimestamp(); $time += $seconds) {
                $series[$name][] = [$time, (float) ($counts[$level][$time] ?? 0)];
            }
        }

        return $series;
    }
}
