<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;

/**
 * InnoDB buffer pool hit ratio since the server started. A low ratio means
 * many reads go to disk: the pool may be too small for the working set.
 */
class BufferPoolCheck extends MariaDbCheck
{
    public const WARNING_BELOW_PERCENT = 95;

    // Too few reads since start for the ratio to mean anything.
    public const MIN_READ_REQUESTS = 10000;

    public function __construct(private readonly int $warningBelowPercent = self::WARNING_BELOW_PERCENT)
    {
    }

    public function key(): string
    {
        return 'innodb_buffer_pool';
    }

    public function label(): string
    {
        return 'InnoDB buffer pool';
    }

    public function run(PDO $pdo): CheckResult
    {
        $status = $this->globalStatus($pdo, ['Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads']);

        if ($status === []) {
            return $this->result(HealthStatus::Ok, 'InnoDB is not in use.');
        }

        return $this->evaluate((int) ($status['Innodb_buffer_pool_read_requests'] ?? 0), (int) ($status['Innodb_buffer_pool_reads'] ?? 0));
    }

    public function evaluate(int $readRequests, int $diskReads): CheckResult
    {
        if ($readRequests < self::MIN_READ_REQUESTS) {
            return $this->result(HealthStatus::Ok, "Not enough reads since start to judge ($readRequests).");
        }

        $percent = round((1 - $diskReads / $readRequests) * 100, 2);
        $summary = "$percent% of reads served from memory since start.";
        $details = ['read_requests' => $readRequests, 'disk_reads' => $diskReads];

        if ($percent < $this->warningBelowPercent) {
            return $this->result(HealthStatus::Warning, "$summary Consider a larger innodb_buffer_pool_size.", $percent, '%', $details);
        }

        return $this->result(HealthStatus::Ok, $summary, $percent, '%', $details);
    }
}
