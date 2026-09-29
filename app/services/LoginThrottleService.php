<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Utils\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Rate limits for everything that checks a code or one-time password:
 * sign-in, first-login setup and re-confirming (revealing a password).
 *
 * Failures are counted per client IP and per username (across all IPs), in
 * sliding windows. A 6-digit code is the only secret at sign-in, so a
 * username also has a daily cap: at most MAX_FAILURES_PER_USERNAME_PER_DAY
 * guesses a day. Checks happen before any hashing, and a limited request
 * gets the same answer whether or not the username exists.
 */
class LoginThrottleService
{
    public const WINDOW_SECONDS = 900;

    public const MAX_FAILURES_PER_IP = 20;

    public const MAX_FAILURES_PER_USERNAME = 10;

    public const DAY_SECONDS = 86400;

    public const MAX_FAILURES_PER_USERNAME_PER_DAY = 30;

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * Seconds until this IP and username may try again; 0 if allowed now.
     */
    public function loginRetryAfter(string $ip, string $username): int
    {
        return max(
            $this->retryAfter($this->bucket('ip', $ip), self::MAX_FAILURES_PER_IP, self::WINDOW_SECONDS),
            $this->retryAfter($this->bucket('user', $username), self::MAX_FAILURES_PER_USERNAME, self::WINDOW_SECONDS),
            $this->retryAfter($this->bucket('user', $username), self::MAX_FAILURES_PER_USERNAME_PER_DAY, self::DAY_SECONDS),
        );
    }

    public function recordLoginFailure(string $ip, string $username): void
    {
        $this->record($this->bucket('ip', $ip));
        $this->record($this->bucket('user', $username));
    }

    /**
     * A successful sign-in clears that username's failures. The IP's count
     * is kept, so one account can't be used to reset an attacker's budget.
     */
    public function recordLoginSuccess(string $username): void
    {
        LoginAttempt::query()->where('bucket', $this->bucket('user', $username))->delete();
    }

    private function retryAfter(string $bucket, int $max, int $window): int
    {
        $now = $this->now();

        $attempts = LoginAttempt::query()
            ->where('bucket', $bucket)
            ->where('attempted_at', '>', $now - $window)
            ->orderBy('attempted_at', 'desc')
            ->limit($max)
            ->pluck('attempted_at');

        if ($attempts->count() < $max) {
            return 0;
        }

        // Allowed again once the oldest of the last $max attempts leaves the window.
        return max(1, (int) $attempts->last() + $window - $now);
    }

    private function record(string $bucket): void
    {
        $now = $this->now();

        LoginAttempt::query()->create(['bucket' => $bucket, 'attempted_at' => $now]);
        LoginAttempt::query()
            ->where('attempted_at', '<=', $now - self::DAY_SECONDS)
            ->delete();
    }

    private function bucket(string $type, string $value): string
    {
        return hash('sha256', $type . ':' . ($type === 'user' ? mb_strtolower($value) : $value));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
