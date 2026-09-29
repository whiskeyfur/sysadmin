<?php

namespace App\Services\Checks;

use App\Contracts\SshHealthCheck;
use App\DTOs\CheckResult;
use App\Enums\HealthStatus;

/**
 * Shared helpers for SSH checks.
 */
abstract class SshCheck implements SshHealthCheck
{
    /**
     * @param array<string, mixed> $details
     */
    protected function result(HealthStatus $status, string $summary, ?float $value = null, ?string $unit = null, array $details = []): CheckResult
    {
        return new CheckResult($this->key(), $this->label(), $status, $summary, $value, $unit, $details);
    }

    protected static function threshold(float $value, float $warning, float $critical): HealthStatus
    {
        return match (true) {
            $value >= $critical => HealthStatus::Critical,
            $value >= $warning => HealthStatus::Warning,
            default => HealthStatus::Ok,
        };
    }

    protected static function bytes(float $kilobytes): string
    {
        foreach (['TB' => 1024 ** 3, 'GB' => 1024 ** 2, 'MB' => 1024] as $unit => $size) {
            if ($kilobytes >= $size) {
                return round($kilobytes / $size, 1) . " $unit";
            }
        }

        return round($kilobytes) . ' KB';
    }
}
