<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;
use PDOException;

/**
 * Bytes read from and written to the database's files since the server
 * started, from performance_schema (file_summary_by_event_name). The
 * report turns successive runs into throughput. Optional: with
 * performance_schema off or not readable, it says so and stays OK.
 */
class FileIoCheck extends MariaDbCheck
{
    public function key(): string
    {
        return 'file_io';
    }

    public function label(): string
    {
        return 'File I/O';
    }

    public function run(PDO $pdo): CheckResult
    {
        if (($this->globalVariables($pdo, ['performance_schema'])['performance_schema'] ?? 'OFF') !== 'ON') {
            return $this->evaluate(null, null, 'off');
        }

        try {
            $row = $pdo->query('SELECT SUM(SUM_NUMBER_OF_BYTES_READ) AS bytes_read, SUM(SUM_NUMBER_OF_BYTES_WRITE) AS bytes_written FROM performance_schema.file_summary_by_event_name')?->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1044, 1045], true)) {
                return $this->evaluate(null, null, 'denied');
            }

            throw $e;
        }

        return $this->evaluate((int) ($row['bytes_read'] ?? 0), (int) ($row['bytes_written'] ?? 0));
    }

    /**
     * @param 'off'|'denied'|null $unavailable why there's nothing to read
     */
    public function evaluate(?int $read, ?int $written, ?string $unavailable = null): CheckResult
    {
        return match (true) {
            $unavailable === 'off' => $this->result(HealthStatus::Ok, 'Not available: performance_schema is off (performance_schema=ON in the server options, then restart).'),
            $unavailable === 'denied' => $this->result(HealthStatus::Ok, 'Not available: the monitoring user needs SELECT on performance_schema.'),
            default => $this->result(
                HealthStatus::Ok,
                'Since the server started: ' . self::size((int) $read) . ' read, ' . self::size((int) $written) . ' written.',
                round(((int) $read + (int) $written) / 1048576, 1),
                'MB',
                ['bytes_read' => (int) $read, 'bytes_written' => (int) $written],
            ),
        };
    }

    public static function size(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1) . " $unit";
            }
        }

        return "$bytes bytes";
    }
}
