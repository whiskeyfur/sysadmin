<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;
use PDOException;

/**
 * Replica threads and lag, for servers that replicate from another server.
 * Handles MariaDB multi-source ("ALL ... STATUS") and MySQL, old and new
 * terminology.
 */
class ReplicationCheck extends MariaDbCheck
{
    public const WARNING_LAG_SECONDS = 60;

    public const CRITICAL_LAG_SECONDS = 600;

    public function __construct(
        private readonly int $warningLagSeconds = self::WARNING_LAG_SECONDS,
        private readonly int $criticalLagSeconds = self::CRITICAL_LAG_SECONDS,
    ) {
    }

    private const STATEMENTS = ['SHOW ALL REPLICAS STATUS', 'SHOW ALL SLAVES STATUS', 'SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'];

    private const ACCESS_DENIED = [1045, 1142, 1227];

    public function key(): string
    {
        return 'replication';
    }

    public function label(): string
    {
        return 'Replication';
    }

    public function run(PDO $pdo): CheckResult
    {
        foreach (self::STATEMENTS as $statement) {
            try {
                return $this->evaluate($pdo->query($statement)?->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } catch (PDOException $e) {
                if (in_array((int) ($e->errorInfo[1] ?? 0), self::ACCESS_DENIED, true)) {
                    return $this->result(HealthStatus::Unknown, 'The monitoring user may not read replication status. Grant it REPLICA MONITOR (MariaDB 10.5+) or REPLICATION CLIENT.');
                }
                // Syntax not supported by this server version: try the next form.
            }
        }

        return $this->result(HealthStatus::Unknown, "Couldn't read replication status.");
    }

    /**
     * @param list<array<string, mixed>> $replicas one row per replication connection
     */
    public function evaluate(array $replicas): CheckResult
    {
        if ($replicas === []) {
            return $this->result(HealthStatus::Ok, 'Not a replica.');
        }

        $status = HealthStatus::Ok;
        $lines = [];
        $maxLag = 0;

        foreach ($replicas as $row) {
            $name = (string) ($row['Connection_name'] ?? '') ?: 'default';
            $io = (string) ($row['Replica_IO_Running'] ?? $row['Slave_IO_Running'] ?? '');
            $sql = (string) ($row['Replica_SQL_Running'] ?? $row['Slave_SQL_Running'] ?? '');
            $lag = $row['Seconds_Behind_Source'] ?? $row['Seconds_Behind_Master'] ?? null;
            $error = trim((string) ($row['Last_IO_Error'] ?? '') . ' ' . (string) ($row['Last_SQL_Error'] ?? ''));

            if ($io !== 'Yes' || $sql !== 'Yes') {
                $status = HealthStatus::Critical;
                $lines[] = "$name: replication stopped (IO $io, SQL $sql)" . ($error !== '' ? ": $error" : '.');

                continue;
            }

            $lag = (int) $lag;
            $maxLag = max($maxLag, $lag);
            $lagStatus = match (true) {
                $lag >= $this->criticalLagSeconds => HealthStatus::Critical,
                $lag >= $this->warningLagSeconds => HealthStatus::Warning,
                default => HealthStatus::Ok,
            };
            $status = HealthStatus::worst([$status, $lagStatus]);
            $lines[] = "$name: running, {$lag} s behind.";
        }

        return $this->result($status, implode(' ', $lines), $maxLag, 's', ['replicas' => count($replicas)]);
    }
}
