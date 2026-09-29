<?php

namespace App\Services;

use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use Carbon\Carbon;

/**
 * Apache reports: requests, server errors and traffic from the access logs
 * (apache_traffic, summed over all of the server's access logs), busy
 * workers from mod_status runs, and error log entries.
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
    public function report(Server $server, string $range = self::DEFAULT_RANGE): array
    {
        [$from, $to] = $this->window($range);
        $span = $to->getTimestamp() - $from->getTimestamp();
        $bucket = $span / 300 > self::MAX_POINTS ? $this->bucketMinutes($span) : 5;
        $seconds = $bucket * 60;
        $intervals = [];

        foreach (ApacheTraffic::query()->where('server_id', $server->id)->where('bucket_at', '>=', $from)->get() as $row) {
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

        $workers = StoredCheck::query()->where('server_id', $server->id)->where('check_key', 'apache_workers')->where('unit', '%')
            ->where('checked_at', '>=', $from)->get()->sortBy('checked_at')
            ->map(fn (StoredCheck $c) => [(int) $c->checked_at->getTimestamp(), (float) $c->value])->values()->all();

        if (count($workers) > self::MAX_POINTS) {
            $workers = $this->averageSeries($workers, $this->bucketMinutes($span) * 60, 'max');
        }

        /** @var list<ApacheLogEntry> $log */
        $log = ApacheLogEntry::query()->where('server_id', $server->id)->where('logged_at', '>=', $from)
            ->orderByDesc('logged_at')->orderByDesc('id')->limit(self::MAX_ENTRIES)->get()->all();

        return [
            'from' => $from,
            'to' => $to,
            'bucket_minutes' => $bucket,
            'requests' => array_filter($requests),
            'traffic' => array_filter($traffic),
            'workers' => $workers === [] ? [] : ['Busy workers' => $workers],
            'log_counts' => $this->logCounts($server, $from, $to, max(60, $bucket)),
            'rows' => array_reverse($rows),
            'log' => $log,
        ];
    }

    /**
     * Error log entries per level and interval, zero-filled.
     *
     * @return array<string, list<array{0: int, 1: float}>>
     */
    private function logCounts(Server $server, Carbon $from, Carbon $to, int $minutes): array
    {
        $seconds = $minutes * 60;
        $counts = [];

        foreach (ApacheLogEntry::query()->where('server_id', $server->id)->where('logged_at', '>=', $from)->whereIn('level', array_keys(self::LOG_LEVELS))->get(['level', 'logged_at']) as $entry) {
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
