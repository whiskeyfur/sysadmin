<?php

namespace App\DTOs;

use App\Enums\HealthStatus;

/**
 * The outcome of one health check.
 */
class CheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly HealthStatus $status,
        public readonly string $summary,
        public readonly ?float $value = null,
        public readonly ?string $unit = null,
        public readonly array $details = [],
    ) {
    }
}
