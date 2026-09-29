<?php

namespace App\Services;

use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Leaf\Helpers\Password;
use Psr\Clock\ClockInterface;

/**
 * Password hashing (Argon2id via Leaf's password helper), the password
 * policy, 30-day expiry and admin-issued temporary passwords.
 */
class PasswordService
{
    public const MIN_LENGTH = 12;

    public const MAX_AGE_DAYS = 30;

    private const TEMPORARY_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function hash(string $password): string
    {
        return Password::hash($password, Password::ARGON2);
    }

    public function verify(User $user, string $password): bool
    {
        return Password::verify($password, $user->password);
    }

    /**
     * Burn the same time as a real check, for usernames that don't exist.
     */
    public function verifyAgainstNothing(string $password): void
    {
        Password::verify($password, $this->dummyHash());
    }

    /**
     * Why $new isn't acceptable, or null if it is.
     */
    public function policyError(string $new, ?string $current = null): ?string
    {
        if (mb_strlen($new) < self::MIN_LENGTH) {
            return 'Passwords must be at least ' . self::MIN_LENGTH . ' characters.';
        }

        if ($current !== null && hash_equals($current, $new)) {
            return 'Choose a password different from the current one.';
        }

        return null;
    }

    /**
     * Set a password the user chose themselves: starts a new 30-day period.
     */
    public function setChosenPassword(User $user, string $password): void
    {
        $user->password = $this->hash($password);
        $user->must_change_password = false;
        $user->password_changed_at = Carbon::instance($this->clock->now());
    }

    /**
     * Set an admin-issued temporary password, which must be changed at first sign-in.
     */
    public function setTemporaryPassword(User $user, string $password): void
    {
        $user->password = $this->hash($password);
        $user->must_change_password = true;
        $user->password_changed_at = null;
    }

    public function isExpired(User $user): bool
    {
        if ($user->password_changed_at === null) {
            return true;
        }

        $expiresAt = $user->password_changed_at->getTimestamp() + self::MAX_AGE_DAYS * 86400;

        return $this->clock->now()->getTimestamp() >= $expiresAt;
    }

    /**
     * Random, easy to read aloud: 4 groups of 4 without look-alike characters.
     */
    public function temporaryPassword(): string
    {
        $alphabet = self::TEMPORARY_PASSWORD_ALPHABET;
        $groups = [];

        for ($group = 0; $group < 4; $group++) {
            $chars = '';

            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $groups[] = $chars;
        }

        return implode('-', $groups);
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= $this->hash(random_bytes(16));
    }
}
