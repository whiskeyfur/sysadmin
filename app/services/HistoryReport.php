<?php

namespace App\Services;

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
     * More runs than this are averaged into time buckets, for readable
     * charts and a table of sane length.
     */
    public const MAX_POINTS = 300;

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
        $hours = (self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE])[1];
        $to = Carbon::instance($this->clock->now());

        return [$to->copy()->subHours($hours), $to];
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
     * @param bool $worst take each bucket's highest value instead of the average (e.g. crashed tables)
     * @return list<array{0: int, 1: float}> one point per bucket, at the bucket's start
     */
    protected function averageSeries(array $points, int $seconds, bool $worst = false): array
    {
        $buckets = [];

        foreach ($points as [$time, $value]) {
            $buckets[intdiv($time, $seconds) * $seconds][] = $value;
        }

        $averaged = [];

        foreach ($buckets as $time => $values) {
            $averaged[] = [$time, $worst ? max($values) : round(array_sum($values) / count($values), 2)];
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
}
