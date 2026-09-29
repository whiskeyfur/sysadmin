<?php

namespace App\Services\Checks;

use App\Contracts\HealthCheck;
use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;

/**
 * Shared helpers for MariaDB/MySQL checks.
 */
abstract class MariaDbCheck implements HealthCheck
{
    /**
     * @param list<string> $names
     * @return array<string, string>
     */
    protected function globalStatus(PDO $pdo, array $names): array
    {
        return $this->keyValue($pdo, 'SHOW GLOBAL STATUS', $names);
    }

    /**
     * @param list<string> $names
     * @return array<string, string>
     */
    protected function globalVariables(PDO $pdo, array $names): array
    {
        return $this->keyValue($pdo, 'SHOW GLOBAL VARIABLES', $names);
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function result(HealthStatus $status, string $summary, ?float $value = null, ?string $unit = null, array $details = []): CheckResult
    {
        return new CheckResult($this->key(), $this->label(), $status, $summary, $value, $unit, $details);
    }

    /**
     * @param list<string> $names
     * @return array<string, string>
     */
    private function keyValue(PDO $pdo, string $statement, array $names): array
    {
        $in = implode(', ', array_map(fn (string $name) => $pdo->quote($name), $names));
        $rows = $pdo->query("$statement WHERE Variable_name IN ($in)")?->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        return array_map('strval', $rows);
    }
}
