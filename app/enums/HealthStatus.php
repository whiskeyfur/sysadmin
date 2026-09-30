<?php

namespace App\Enums;

/**
 * Outcome of one health check. Ordered by severity so the worst of a run
 * can be picked; "unknown" (the check couldn't run) ranks between warning
 * and critical so it's never hidden behind an OK.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Unknown = 'unknown';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Unknown => 2,
            self::Critical => 3,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @param iterable<self> $statuses
     */
    /**
     * An admin setting's level: 0 note (OK), 1 warning, 2 critical.
     */
    public static function fromLevel(int $level): self
    {
        return match ($level) {
            1 => self::Warning, 2 => self::Critical, default => self::Ok
        };
    }

    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
