<?php

namespace App\Services;

use App\Models\ApacheAccessEntry;
use App\Models\ApacheLogEntry;
use App\Models\ApacheTraffic;
use App\Models\ApacheVhost;
use App\Models\BlocklistIp;
use App\Models\Fail2banProtection;
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
    /**
     * The most error log entries an access log row folds out.
     */
    public const ERRORS_PER_REQUEST = 20;

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
            'requests' => array_filter($requests),
            'traffic' => array_filter($traffic),
            'workers' => $workers === [] ? [] : ['Busy workers' => $workers],
            'log_counts' => $this->logCounts($server, $logs, $from, $to, max(60, $bucket)),
            'rows' => array_reverse($rows),
        ];
    }

    /**
     * One page of the access log in the period, newest first unless sorted otherwise. $search matches
     * client, host, request, referer, user agent or request ID (a 3-digit number: that status too).
     * $filters, each "in" (only those), "out" (not those) or absent (any):
     *   statuses: class => in/out (2 for 2xx ... 5; the "in" ones any of them),
     *   banned: addresses fail2ban had banned at the last read, in any jail,
     *   protected: addresses protected from banning,
     *   listed: addresses the downloaded blocklists name,
     *   local: 127.* and ::1;
     * and client: a whole address exactly, else from its start. Different filters all apply.
     *
     * @param array{statuses?: array<int, string>, client?: string, banned?: string, protected?: string, listed?: string, local?: string} $filters
     * @return array{rows: list<ApacheAccessEntry>, errors: array<string, int>, error_entries: array<string, list<ApacheLogEntry>>, total: int, page: int, pages: int} errors: per request ID on the page, how many error log entries it has; error_entries: those entries (see errorEntries())
     */
    public function accessPage(Server $server, string $range, ?ApacheVhost $vhost = null, int $page = 1, string $search = '', string $sort = 'time', string $direction = 'desc', array $filters = []): array
    {
        [$from, $to] = $this->window($range);
        $query = $this->accessQuery($server, $from, $to, $vhost, $vhost === null ? null : $this->vhostLogs($server, $vhost));
        $search = trim($search);
        $client = trim((string) ($filters['client'] ?? ''));
        $statuses = array_intersect_key((array) ($filters['statuses'] ?? []), array_flip([2, 3, 4, 5]));
        $in = array_keys(array_filter($statuses, fn ($state) => $state === 'in'));
        $out = array_keys(array_filter($statuses, fn ($state) => $state === 'out'));
        $class = fn (int $c) => [$c * 100, $c * 100 + 99];

        // Status classes: any of those included; none of those excluded.
        if ($in !== []) {
            $query->where(function ($q) use ($in, $class) {
                foreach ($in as $c) {
                    $q->orWhereBetween('status', $class($c));
                }
            });
        }

        foreach ($out as $c) {
            $query->whereNotBetween('status', $class($c));
        }

        // Client: a whole address exactly; anything else from its start, so "10.0.0." matches a subnet.
        if (filter_var($client, FILTER_VALIDATE_IP) !== false) {
            $query->where('client', $client);
        } elseif ($client !== '') {
            $query->where('client', 'like', addcslashes($client, '%_\\') . '%');
        }

        // fail2ban: banned (in any jail, at the last read) and protected addresses, each in or out.
        $lists = [
            'banned' => fn () => array_values(array_unique(array_merge([], ...array_values(array_map(fn ($list) => (array) $list, $server->fail2ban_bans ?? []))))),
            'protected' => fn () => Fail2banProtection::ipsFor($server),
        ];

        foreach ($lists as $name => $list) {
            $state = $filters[$name] ?? '';

            if ($state === 'in' || $state === 'out') {
                $this->clientIn($query, $list(), $state === 'in');
            }
        }

        // Listed: named by one of the downloaded blocklists (BlocklistService).
        $listed = BlocklistIp::query()->whereNotNull('sources')->select('ip');

        if (($filters['listed'] ?? '') === 'in') {
            $query->whereIn('client', $listed);
        } elseif (($filters['listed'] ?? '') === 'out') {
            $query->where(fn ($q) => $q->whereNull('client')->orWhereNotIn('client', $listed));
        }

        // Localhost: 127.* and ::1.
        $local = fn ($q) => $q->where('client', 'like', '127.%')->orWhereIn('client', ['::1', 'localhost']);

        if (($filters['local'] ?? '') === 'in') {
            $query->where($local);
        } elseif (($filters['local'] ?? '') === 'out') {
            $query->where(fn ($q) => $q->whereNull('client')->orWhere(fn ($q) => $q->where('client', 'not like', '127.%')->whereNotIn('client', ['::1', 'localhost'])));
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like, $search) {
                foreach (['client', 'vhost', 'method', 'path', 'referer', 'agent'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }

                $q->orWhere('request_id', $search);

                if (preg_match('/^\d{3}$/', $search) === 1) {
                    $q->orWhere('status', (int) $search);
                }
            });
        }

        $result = $this->paginate($query, self::ACCESS_SORTS[$sort] ?? 'requested_at', $direction, $page);
        /** @var list<ApacheAccessEntry> $rows */
        $rows = $result['rows'];

        return ['rows' => $rows, 'errors' => $this->errorCounts($server, $rows), 'error_entries' => $this->errorEntries($server, $rows)] + $result;
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
            // Notices are stored as "note": "notice" (or its start) finds them too.
            $notice = str_starts_with('notice', strtolower($search)) && strlen($search) >= 3;
            $query->where(fn ($q) => $q->orWhere('message', 'like', $like)->orWhere('level', 'like', $like)->orWhere('source', 'like', $like)
                ->orWhere('request_id', $search)->when($notice, fn ($q) => $q->orWhere('level', 'note')));
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
     * Only requests from these addresses ($in), or none from them. In chunks: databases limit how many
     * values one IN () takes.
     *
     * @param \Illuminate\Database\Eloquent\Builder<ApacheAccessEntry> $query
     * @param list<string> $ips
     */
    private function clientIn(\Illuminate\Database\Eloquent\Builder $query, array $ips, bool $in): void
    {
        if ($in) {
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

    /**
     * The error log entries of each request on a page (by mod_unique_id's request ID), oldest first, at
     * most ERRORS_PER_REQUEST each (errorCounts() has how many there are), for the access log's fold-outs.
     *
     * @param list<ApacheAccessEntry> $rows
     * @return array<string, list<ApacheLogEntry>>
     */
    private function errorEntries(Server $server, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (ApacheAccessEntry $r) => $r->request_id, $rows))));

        if ($ids === []) {
            return [];
        }

        $entries = [];
        /** @var list<ApacheLogEntry> $found */
        $found = ApacheLogEntry::query()->where('server_id', $server->id)->whereIn('request_id', $ids)->orderBy('logged_at')->orderBy('id')->get()->all();

        foreach ($found as $entry) {
            if (count($entries[$entry->request_id] ?? []) < self::ERRORS_PER_REQUEST) {
                $entries[$entry->request_id][] = $entry;
            }
        }

        return $entries;
    }

    /**
     * How many error log entries each request on a page has (by mod_unique_id's request ID).
     *
     * @param list<ApacheAccessEntry> $rows
     * @return array<string, int>
     */
    private function errorCounts(Server $server, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (ApacheAccessEntry $r) => $r->request_id, $rows))));

        if ($ids === []) {
            return [];
        }

        return array_map('intval', ApacheLogEntry::query()->where('server_id', $server->id)->whereIn('request_id', $ids)
            ->groupBy('request_id')->selectRaw('request_id, COUNT(*) AS n')->pluck('n', 'request_id')->all());
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
