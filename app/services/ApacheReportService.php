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
    /**
     * Sortable columns of those tables => database column.
     */
    private const ACCESS_SORTS = ['time' => 'requested_at', 'client' => 'client', 'host' => 'vhost', 'request' => 'path', 'status' => 'status', 'size' => 'bytes', 'took' => 'duration_ms'];

    private const ERROR_SORTS = ['time' => 'logged_at', 'level' => 'level', 'log' => 'source'];

    private const LOG_LEVELS = ['crash' => 'Crashes', 'error' => 'Errors', 'warning' => 'Warnings'];

    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     bucket_minutes: int,
     *     access_total: int,
     *     log_total: int,
     *     access_statuses: list<int>,
     *     requests: array<string, list<array{0: int, 1: float}>>,
     *     traffic: array<string, list<array{0: int, 1: float}>>,
     *     workers: array<string, list<array{0: int, 1: float}>>,
     *     log_counts: array<string, list<array{0: int, 1: float}>>,
     *     rows: list<array{time: Carbon, requests: int, bytes: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int}>
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

        return [
            'from' => $from,
            'to' => $to,
            'bucket_minutes' => $bucket,
            'access_total' => $this->accessQuery($server, $from, $to, $vhost, $logs)->count(),
            'log_total' => $this->entries($server, $logs)->whereBetween('logged_at', [$from, $to])->count(),
            'access_statuses' => array_map('intval', $this->accessQuery($server, $from, $to, $vhost, $logs)->distinct()->orderBy('status')->pluck('status')->all()),
            'requests' => array_filter($requests),
            'traffic' => array_filter($traffic),
            'workers' => $workers === [] ? [] : ['Busy workers' => $workers],
            'log_counts' => $this->logCounts($server, $logs, $from, $to, max(60, $bucket)),
            'rows' => array_reverse($rows),
        ];
    }

    /**
     * One page of the access log in the period, newest first unless sorted otherwise. $search matches
     * client, host, request, referer or user agent (a 3-digit number: that status too). $filters:
     * status ("4xx", "errors" for 4xx and 5xx, or a code), client (a whole address exactly, else from its start) and
     * hide_local (leave out 127.* and ::1) and banned ("yes": addresses fail2ban had banned at the last
     * read, in any jail; "no": the others).
     *
     * @param array{status?: string, client?: string, hide_local?: bool, banned?: string} $filters
     * @return array{rows: list<ApacheAccessEntry>, total: int, page: int, pages: int}
     */
    public function accessPage(Server $server, string $range, ?ApacheVhost $vhost = null, int $page = 1, string $search = '', string $sort = 'time', string $direction = 'desc', array $filters = []): array
    {
        [$from, $to] = $this->window($range);
        $query = $this->accessQuery($server, $from, $to, $vhost, $vhost === null ? null : $this->vhostLogs($server, $vhost));
        $search = trim($search);
        $status = (string) ($filters['status'] ?? '');
        $client = trim((string) ($filters['client'] ?? ''));

        // Status: a class (4xx), client and server errors together, or one code.
        if (preg_match('/^([1-5])xx$/', $status, $m) === 1) {
            $query->whereBetween('status', [(int) $m[1] * 100, (int) $m[1] * 100 + 99]);
        } elseif ($status === 'errors') {
            $query->where('status', '>=', 400);
        } elseif (preg_match('/^\d{3}$/', $status) === 1) {
            $query->where('status', (int) $status);
        }

        // Client: a whole address exactly; anything else from its start, so "10.0.0." matches a subnet.
        if (filter_var($client, FILTER_VALIDATE_IP) !== false) {
            $query->where('client', $client);
        } elseif ($client !== '') {
            $query->where('client', 'like', addcslashes($client, '%_\\') . '%');
        }

        // Banned: the addresses fail2ban had banned at the last read (in any jail), or not.
        $banned = (string) ($filters['banned'] ?? '');

        if (in_array($banned, ['yes', 'no'], true)) {
            $ips = array_values(array_unique(array_merge([], ...array_values(array_map(fn ($list) => (array) $list, $server->fail2ban_bans ?? [])))));

            if ($banned === 'yes') {
                // In chunks: databases limit how many values one IN () takes.
                $query->where(function ($q) use ($ips) {
                    $q->whereRaw('1 = 0');

                    foreach (array_chunk($ips, 1000) as $chunk) {
                        $q->orWhereIn('client', $chunk);
                    }
                });
            } elseif ($ips !== []) {
                $query->where(fn ($q) => $q->whereNull('client')->orWhere(function ($q) use ($ips) {
                    foreach (array_chunk($ips, 1000) as $chunk) {
                        $q->whereNotIn('client', $chunk);
                    }
                }));
            }
        }

        if (!empty($filters['hide_local'])) {
            $query->where(fn ($q) => $q->whereNull('client')->orWhere(fn ($q) => $q->where('client', 'not like', '127.%')->whereNotIn('client', ['::1', 'localhost'])));
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like, $search) {
                foreach (['client', 'vhost', 'method', 'path', 'referer', 'agent'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }

                if (preg_match('/^\d{3}$/', $search) === 1) {
                    $q->orWhere('status', (int) $search);
                }
            });
        }

        $result = $this->paginate($query, self::ACCESS_SORTS[$sort] ?? 'requested_at', $direction, $page);
        /** @var list<ApacheAccessEntry> $rows */
        $rows = $result['rows'];

        return ['rows' => $rows] + $result;
    }

    /**
     * One page of the error log in the period; $search matches the message, level or log file.
     *
     * @return array{rows: list<ApacheLogEntry>, total: int, page: int, pages: int}
     */
    public function errorPage(Server $server, string $range, ?ApacheVhost $vhost = null, int $page = 1, string $search = '', string $sort = 'time', string $direction = 'desc'): array
    {
        [$from, $to] = $this->window($range);
        $query = $this->entries($server, $vhost === null ? null : $this->vhostLogs($server, $vhost));
        $query->whereBetween('logged_at', [$from, $to]);
        $search = trim($search);

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(fn ($q) => $q->orWhere('message', 'like', $like)->orWhere('level', 'like', $like)->orWhere('source', 'like', $like));
        }

        if ($sort === 'level') {
            // By severity, not alphabetically.
            $query->orderByRaw("CASE level WHEN 'crash' THEN 3 WHEN 'error' THEN 2 WHEN 'warning' THEN 1 ELSE 0 END " . ($direction === 'asc' ? 'ASC' : 'DESC'));
        }

        $result = $this->paginate($query, self::ERROR_SORTS[$sort] ?? 'logged_at', $direction, $page, $sort === 'level');
        /** @var list<ApacheLogEntry> $rows */
        $rows = $result['rows'];

        return ['rows' => $rows] + $result;
    }

    /**
     * Stored requests in the period; for a virtual host, those in its logs, and from a shared log (e.g.
     * other_vhosts_access.log) only its own.
     *
     * @param array{access: list<string>, error: list<string>, shared: list<string>}|null $logs
     * @return \Illuminate\Database\Eloquent\Builder<ApacheAccessEntry>
     */
    private function accessQuery(Server $server, Carbon $from, Carbon $to, ?ApacheVhost $vhost, ?array $logs): \Illuminate\Database\Eloquent\Builder
    {
        $query = ApacheAccessEntry::query();
        $query->where('server_id', $server->id)->whereBetween('requested_at', [$from, $to]);

        if ($logs !== null) {
            $query->whereIn('source', $logs['access']);
        }

        if ($vhost?->name !== null) {
            $name = $vhost->name;
            $query->where(fn ($q) => $q->whereNull('vhost')->orWhere('vhost', $name)->orWhere('vhost', 'like', addcslashes($name, '%_\\') . ':%'));
        }

        return $query;
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
