<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;

/**
 * System load: the 5-minute load average per CPU core (/proc/loadavg).
 * 1.0 per core means the CPUs were fully busy on average.
 */
class LoadCheck extends SshCheck
{
    public const WARNING_PER_CORE = 1.0;

    public const CRITICAL_PER_CORE = 2.0;

    public function key(): string
    {
        return 'load';
    }

    public function label(): string
    {
        return 'Load';
    }

    public function command(): string
    {
        return 'cat /proc/loadavg 2>/dev/null; getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null';
    }

    public function evaluate(string $output): CheckResult
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $averages = preg_split('/\s+/', trim($lines[0] ?? ''));
        $cores = (int) trim($lines[1] ?? '');

        if (!is_array($averages) || count($averages) < 3 || !is_numeric($averages[1])) {
            return $this->result(HealthStatus::Unknown, "Couldn't read the load average (/proc/loadavg). Only Linux is supported so far.");
        }

        [$one, $five, $fifteen] = array_map('floatval', array_slice($averages, 0, 3));
        $cores = max(1, $cores);
        $perCore = round($five / $cores, 2);
        $summary = "Load $one, $five, $fifteen (1, 5, 15 min) on $cores core" . ($cores === 1 ? '' : 's') . "; {$perCore} per core.";

        return $this->result(
            self::threshold($perCore, self::WARNING_PER_CORE, self::CRITICAL_PER_CORE),
            $summary,
            $perCore,
            'per core',
            ['load1' => $one, 'load5' => $five, 'load15' => $fifteen, 'cores' => $cores],
        );
    }
}
