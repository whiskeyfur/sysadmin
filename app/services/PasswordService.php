<?php

namespace App\Services;

use App\Models\User;
use Leaf\Helpers\Password;

/**
 * One-time passwords. Users sign in with their username and an
 * authenticator code; there are no user passwords. An admin sets a one-time
 * password on new accounts and resets, and it's only good for the first
 * sign-in, where the user enrols their authenticator. It's hashed with
 * Argon2id (Leaf's password helper) and discarded once used.
 */
class PasswordService
{
    public const MIN_LENGTH = 8;

    public function hash(string $password): string
    {
        return Password::hash($password, Password::ARGON2);
    }

    /**
     * Whether $password is the user's unused one-time password.
     */
    public function verify(User $user, string $password): bool
    {
        return $user->password !== null && Password::verify($password, $user->password);
    }

    /**
     * Burn the same time as a real check, for usernames that don't exist.
     */
    public function verifyAgainstNothing(string $password): void
    {
        Password::verify($password, $this->dummyHash());
    }

    /**
     * Why $password can't be a one-time password, or null if it can.
     */
    public function policyError(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'One-time passwords must be at least ' . self::MIN_LENGTH . ' characters.';
        }

        if (preg_match('/^\d{6}$/', $password) === 1) {
            return "A one-time password can't look like an authenticator code.";
        }

        return null;
    }

    /**
     * Set an admin-issued one-time password; the user must enrol an
     * authenticator with it before they can sign in.
     */
    public function setOneTimePassword(User $user, string $password): void
    {
        $user->password = $this->hash($password);
        $user->must_change_password = true;
    }

    /**
     * The one-time password has been used: forget it.
     */
    public function discard(User $user): void
    {
        $user->password = null;
        $user->must_change_password = false;
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= $this->hash(random_bytes(16));
    }
}
