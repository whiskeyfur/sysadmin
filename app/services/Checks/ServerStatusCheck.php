<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;

/**
 * Version and uptime. A recent restart is a warning: it's often a crash.
 */
class ServerStatusCheck extends MariaDbCheck
{
    public const RECENT_RESTART_SECONDS = 3600;

    public function key(): string
    {
        return 'server_status';
    }

    public function label(): string
    {
        return 'Server status';
    }

    public function run(PDO $pdo): CheckResult
    {
        $version = (string) $pdo->query('SELECT VERSION()')?->fetchColumn();
        $uptime = (int) ($this->globalStatus($pdo, ['Uptime'])['Uptime'] ?? 0);

        return $this->evaluate($version, $uptime);
    }

    public function evaluate(string $version, int $uptimeSeconds): CheckResult
    {
        $running = 'Running ' . $version . ', up ' . self::duration($uptimeSeconds) . '.';

        if ($uptimeSeconds < self::RECENT_RESTART_SECONDS) {
            return $this->result(HealthStatus::Warning, "$running Restarted recently; check the error log for a crash.", $uptimeSeconds, 's', ['version' => $version]);
        }

        return $this->result(HealthStatus::Ok, $running, $uptimeSeconds, 's', ['version' => $version]);
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400) . ' d ' . intdiv($seconds % 86400, 3600) . ' h',
            $seconds >= 3600 => intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min',
            $seconds >= 60 => intdiv($seconds, 60) . ' min',
            default => $seconds . ' s',
        };
    }
}
