<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;

/**
 * How much space each database takes (data and indexes, from
 * information_schema.TABLES), to track growth. InnoDB's figures are
 * statistics, updated as tables change, so they trail recent writes.
 */
class DatabaseSizeCheck extends MariaDbCheck
{
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'sys'];

    public function key(): string
    {
        return 'database_size';
    }

    public function label(): string
    {
        return 'Database size';
    }

    public function run(PDO $pdo): CheckResult
    {
        $rows = $pdo->query(
            "SELECT table_schema AS name, SUM(data_length + index_length) AS bytes, SUM(data_free) AS free
             FROM information_schema.TABLES WHERE table_schema NOT IN ('" . implode("', '", self::SYSTEM_SCHEMAS) . "')
             GROUP BY table_schema",
        )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->evaluate($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows name, bytes, free per database
     */
    public function evaluate(array $rows): CheckResult
    {
        $sizes = [];
        $free = 0;

        foreach ($rows as $row) {
            $sizes[(string) $row['name']] = (int) $row['bytes'];
            $free += (int) ($row['free'] ?? 0);
        }

        arsort($sizes);
        $total = array_sum($sizes);

        if ($sizes === []) {
            return $this->result(HealthStatus::Ok, 'No databases the monitoring user can see.', 0.0, 'MB', ['databases' => []]);
        }

        $largest = array_slice($sizes, 0, 3, true);
        $summary = FileIoCheck::size($total) . ' in ' . count($sizes) . ' database' . (count($sizes) === 1 ? '' : 's')
            . '; largest ' . implode(', ', array_map(fn ($name, $bytes) => "$name " . FileIoCheck::size($bytes), array_keys($largest), $largest)) . '.';

        return $this->result(HealthStatus::Ok, $summary, round($total / 1048576, 1), 'MB', ['databases' => $sizes, 'free_bytes' => $free]);
    }
}
