<?php

namespace App\Services;

use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\SslCheck;
use Carbon\Carbon;

/**
 * SSL reports: days until expiry over time, from the stored certificate
 * checks (kept SslMonitorService::RETENTION_DAYS). For all certificates, one
 * line each at its soonest-expiring place; for one certificate, one line per
 * place it's served, so a server still serving an old copy stands out.
 */
class SslReportService extends HistoryReport
{
    public const MAX_ROWS = 500;

    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     days: array<string, list<array{0: int, 1: float}>>,
     *     rows: list<SslCheck>,
     *     row_total: int,
     *     bucket_minutes: int|null
     * }
     *
     * days: series name (certificate, or place) => [unix time, days left];
     * a check that got no certificate leaves a gap. rows: the most recent
     * check of each place a certificate is served (up to MAX_ROWS, with
     * binding.certificate and binding.server loaded), soonest to expire
     * first; row_total counts every check in the period. bucket_minutes is set when points were
     * reduced to one per bucket, keeping the lowest days left.
     */
    public function report(?SslCertificate $certificate, string $range = self::DEFAULT_RANGE): array
    {
        [$from, $to] = $this->window($range);
        $bindingIds = $certificate === null
            ? SslBinding::query()->pluck('id')->all()
            : SslBinding::query()->where('certificate_id', $certificate->id)->pluck('id')->all();

        $checks = SslCheck::query()
            ->with(['binding.certificate', 'binding.server'])
            ->whereIn('binding_id', $bindingIds)
            ->whereBetween('checked_at', [$from, $to])
            ->get()
            ->sortBy(['checked_at', 'id']);

        // Series name => time => lowest days left at that time.
        $lowest = [];

        foreach ($checks as $check) {
            $binding = $check->binding;

            if (!$binding instanceof SslBinding || !$binding->certificate instanceof SslCertificate || $check->days_left === null) {
                continue;
            }

            $name = $certificate === null ? $binding->certificate->name : $binding->label();
            $time = (int) $check->checked_at->getTimestamp();
            $lowest[$name][$time] = min($lowest[$name][$time] ?? PHP_FLOAT_MAX, (float) $check->days_left);
        }

        ksort($lowest, SORT_NATURAL | SORT_FLAG_CASE);
        $days = array_map(fn (array $points) => array_map(fn ($time, $value) => [$time, $value], array_keys($points), $points), $lowest);
        $bucket = null;

        if (max(array_map('count', $days) ?: [0]) > self::MAX_POINTS) {
            $bucket = $this->bucketMinutes($to->getTimestamp() - $from->getTimestamp());
            $days = array_map(fn (array $points) => $this->averageSeries($points, $bucket * 60, 'min'), $days);
        }

        // The table: each certificate's most recent check in the period, one per place it's served (a
        // server still serving an old copy stands out), soonest to expire first.
        $latest = [];

        /** @var SslCheck $check */
        foreach ($checks->reverse() as $check) {
            $latest[$check->binding_id] ??= $check;
        }

        usort($latest, fn (SslCheck $a, SslCheck $b) => [$a->days_left ?? -INF, $a->binding->certificate->name ?? ''] <=> [$b->days_left ?? -INF, $b->binding->certificate->name ?? '']);
        $rows = array_slice($latest, 0, self::MAX_ROWS);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'rows' => $rows,
            'row_total' => $checks->count(),
            'bucket_minutes' => $bucket,
        ];
    }
}
