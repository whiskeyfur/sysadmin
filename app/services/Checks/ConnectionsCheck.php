<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;

/**
 * Connections in use versus max_connections.
 */
class ConnectionsCheck extends MariaDbCheck
{
    public const WARNING_PERCENT = 80;

    public const CRITICAL_PERCENT = 95;

    public function __construct(
        private readonly int $warningPercent = self::WARNING_PERCENT,
        private readonly int $criticalPercent = self::CRITICAL_PERCENT,
    ) {
    }

    public function key(): string
    {
        return 'connections';
    }

    public function label(): string
    {
        return 'Connections';
    }

    public function run(PDO $pdo): CheckResult
    {
        $status = $this->globalStatus($pdo, ['Threads_connected', 'Max_used_connections']);
        $max = (int) ($this->globalVariables($pdo, ['max_connections'])['max_connections'] ?? 0);

        return $this->evaluate((int) ($status['Threads_connected'] ?? 0), (int) ($status['Max_used_connections'] ?? 0), $max);
    }

    public function evaluate(int $connected, int $peak, int $max): CheckResult
    {
        if ($max <= 0) {
            return $this->result(HealthStatus::Unknown, "Couldn't read max_connections.");
        }

        $percent = round($connected / $max * 100, 1);
        $summary = "$connected of $max connections in use ($percent%); peak since start $peak.";
        $details = ['connected' => $connected, 'peak' => $peak, 'max' => $max];

        $status = match (true) {
            $percent >= $this->criticalPercent => HealthStatus::Critical,
            $percent >= $this->warningPercent => HealthStatus::Warning,
            default => HealthStatus::Ok,
        };

        return $this->result($status, $summary, $percent, '%', $details);
    }
}
