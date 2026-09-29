<?php

namespace App\Services;

use App\Models\User;
use App\Utils\SystemClock;
use DateTimeInterface;
use DomainException;
use Psr\Clock\ClockInterface;

/**
 * Everyone signed in can test servers and run checks; non-admins wait
 * SECONDS between runs on the same server, so repeated clicks can't pile up
 * logins on servers that ban failed attempts (fail2ban). Admins, who set
 * servers up, aren't limited.
 */
class CheckCooldown
{
    public const SECONDS = 30;

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * @param DateTimeInterface|null $last when this ran last
     *
     * @throws DomainException with a user-facing message while cooling down
     */
    public function require(User $user, ?DateTimeInterface $last, string $what): void
    {
        if ($user->isAdmin() || $last === null) {
            return;
        }

        $wait = $last->getTimestamp() + self::SECONDS - $this->clock->now()->getTimestamp();

        if ($wait > 0) {
            throw new DomainException("$what less than " . self::SECONDS . " seconds ago. Try again in $wait seconds.");
        }
    }
}
