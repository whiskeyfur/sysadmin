<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;

/**
 * Memory in use, from /proc/meminfo: MemAvailable (what can be used without
 * swapping), or free + buffers + cached on kernels older than 3.14. Heavy
 * swap use is a warning too.
 */
class MemoryCheck extends SshCheck
{
    public const WARNING_PERCENT = 90;

    public const CRITICAL_PERCENT = 95;

    public const SWAP_WARNING_PERCENT = 80;

    public function key(): string
    {
        return 'memory';
    }

    public function label(): string
    {
        return 'Memory';
    }

    public function command(): string
    {
        return 'cat /proc/meminfo 2>/dev/null';
    }

    public function evaluate(string $output): CheckResult
    {
        preg_match_all('/^(\w+):\s+(\d+)/m', $output, $matches);
        $info = array_map('floatval', array_combine($matches[1], $matches[2]) ?: []);
        $total = $info['MemTotal'] ?? 0;

        if ($total <= 0) {
            return $this->result(HealthStatus::Unknown, "Couldn't read memory usage (/proc/meminfo). Only Linux is supported so far.");
        }

        $available = $info['MemAvailable'] ?? (($info['MemFree'] ?? 0) + ($info['Buffers'] ?? 0) + ($info['Cached'] ?? 0));
        $percent = round((1 - $available / $total) * 100, 1);
        $status = self::threshold($percent, self::WARNING_PERCENT, self::CRITICAL_PERCENT);
        $summary = "$percent% of " . self::bytes($total) . ' in use (' . self::bytes($available) . ' available).';

        $swapTotal = $info['SwapTotal'] ?? 0;
        $swapPercent = null;

        if ($swapTotal > 0) {
            $swapPercent = round((1 - ($info['SwapFree'] ?? 0) / $swapTotal) * 100, 1);
            $summary .= " Swap {$swapPercent}% of " . self::bytes($swapTotal) . ' used.';

            if ($swapPercent >= self::SWAP_WARNING_PERCENT) {
                $status = HealthStatus::worst([$status, HealthStatus::Warning]);
            }
        } else {
            $summary .= ' No swap.';
        }

        return $this->result($status, $summary, $percent, '%', ['total_kb' => $total, 'available_kb' => $available, 'swap_percent' => $swapPercent]);
    }
}
