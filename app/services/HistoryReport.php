<?php

namespace App\Services;

use App\Utils\LocalTime;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Psr\Clock\ClockInterface;

/**
 * Shared by the reports (SshReportService, MariadbReportService): report
 * periods, and averaging runs into even time buckets when there are too many
 * to chart (e.g. 30 days of 5-minute checks).
 */
abstract class HistoryReport
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

    /**
     * A chosen start and end travel as the range "START-END" (unix times): from the date fields
     * (period()) or from dragging across a chart.
     */
    public const CUSTOM = '/^(\d{9,11})-(\d{9,11})$/';

    /**
     * The shortest and longest chosen period, in seconds.
     */
    public const MIN_SPAN = 600;

    public const MAX_SPAN = 31 * 86400;

    /**
     * More runs than this are averaged into time buckets, for readable
     * charts and a table of sane length.
     */
    public const MAX_POINTS = 300;

    /**
     * Rows per page of the log tables the report pages fetch (see paginate()).
     */
    public const PAGE_SIZE = 100;

    /**
     * Bucket widths to pick from, in minutes.
     */
    private const BUCKET_MINUTES = [5, 10, 15, 30, 60, 120, 240, 360, 720, 1440];

    public function __construct(protected readonly ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * The report's time window for a range key (unknown keys get the default).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function window(string $range): array
    {
        if (($custom = self::custom($range)) !== null) {
            return [Carbon::createFromTimestamp($custom[0]), Carbon::createFromTimestamp($custom[1])];
        }

        $hours = (self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE])[1];
        $to = Carbon::instance($this->clock->now());

        return [$to->copy()->subHours($hours), $to];
    }

    /**
     * The period a report shows, from the request: a start and end (date fields, in the app's time
     * zone), else the range (a preset, or "START-END" from zooming into a chart), else the default.
     * A chosen period is kept between MIN_SPAN and MAX_SPAN (the end moves).
     */
    public static function period(string $range, string $start = '', string $end = ''): string
    {
        if ($start !== '' && $end !== '') {
            // As a datetime-local field sends it (seconds optional).
            $parse = fn (string $value) => \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', strlen($value) === 16 ? "$value:00" : $value, LocalTime::zone());
            [$from, $to] = [$parse($start), $parse($end)];

            if ($from === false || $to === false) {
                return isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
            }

            [$from, $to] = [$from->getTimestamp(), $to->getTimestamp()];
            [$from, $to] = $from <= $to ? [$from, $to] : [$to, $from];

            return $from . '-' . min(max($to, $from + self::MIN_SPAN), $from + self::MAX_SPAN);
        }

        if (isset(self::RANGES[$range])) {
            return $range;
        }

        if (preg_match(self::CUSTOM, $range, $m) === 1 && (int) $m[2] - (int) $m[1] >= self::MIN_SPAN && (int) $m[2] - (int) $m[1] <= self::MAX_SPAN) {
            return $range;
        }

        return self::DEFAULT_RANGE;
    }

    /**
     * A chosen period's start and end (unix times); null for a preset.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function custom(string $range): ?array
    {
        return preg_match(self::CUSTOM, $range, $m) === 1 ? [(int) $m[1], (int) $m[2]] : null;
    }

    protected function bucketMinutes(int $spanSeconds): int
    {
        foreach (self::BUCKET_MINUTES as $minutes) {
            if ($spanSeconds / ($minutes * 60) <= self::MAX_POINTS) {
                return $minutes;
            }
        }

        return self::BUCKET_MINUTES[count(self::BUCKET_MINUTES) - 1];
    }

    /**
     * @param list<array{0: int, 1: float}> $points
     * @param 'average'|'max'|'min' $keep what each bucket keeps: the average, or the highest (e.g. crashed
     *                                    tables) or lowest (e.g. days until expiry) value, so a bad moment isn't averaged away
     * @return list<array{0: int, 1: float}> one point per bucket, at the bucket's start
     */
    protected function averageSeries(array $points, int $seconds, string $keep = 'average'): array
    {
        $buckets = [];

        foreach ($points as [$time, $value]) {
            $buckets[intdiv($time, $seconds) * $seconds][] = $value;
        }

        $averaged = [];

        foreach ($buckets as $time => $values) {
            $averaged[] = [$time, match ($keep) {
                'max' => max($values),
                'min' => min($values),
                default => round(array_sum($values) / count($values), 2),
            }];
        }

        return $averaged;
    }

    /**
     * One row per bucket: $average fields averaged, $latest fields from the
     * bucket's last run that has them, then $extra adds anything else.
     *
     * @param array<int, array<string, mixed>> $rows keyed by run time
     * @param list<string> $average
     * @param list<string> $latest
     * @param (callable(list<array<string, mixed>>, array<string, mixed>): array<string, mixed>)|null $extra
     * @return array<int, array<string, mixed>>
     */
    protected function averageRows(array $rows, int $seconds, array $average, array $latest = [], ?callable $extra = null): array
    {
        $groups = [];

        foreach ($rows as $time => $row) {
            $groups[intdiv($time, $seconds) * $seconds][] = $row;
        }

        $averaged = [];

        foreach ($groups as $time => $group) {
            $row = ['time' => Carbon::createFromTimestamp($time)];

            foreach ($average as $field) {
                $values = array_filter(array_column($group, $field), fn ($v) => $v !== null);
                $row[$field] = $values === [] ? null : round(array_sum($values) / count($values), 2);
            }

            foreach ($latest as $field) {
                $row[$field] = collect($group)->pluck($field)->filter(fn ($v) => $v !== null)->last();
            }

            $averaged[$time] = $extra === null ? $row : $extra($group, $row);
        }

        return $averaged;
    }

    /**
     * One page of a log table, sorted by $column (unless $ordered: the query is sorted already), ties
     * in a stable order. A page past the end gives the last one.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param \Illuminate\Database\Eloquent\Builder<TModel> $query
     * @return array{rows: list<mixed>, total: int, page: int, pages: int}
     */
    protected function paginate(\Illuminate\Database\Eloquent\Builder $query, string $column, string $direction, int $page, bool $ordered = false): array
    {
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        if (!$ordered) {
            $query->orderBy($column, $direction);
        }

        $rows = $query->orderBy('id', $direction)->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get()->all();

        return ['rows' => array_values($rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
