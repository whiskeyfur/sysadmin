<?php

namespace App\Services;

use App\Models\User;
use Leaf\Helpers\Password;

/**
 * Passwords, hashed with Argon2id (Leaf's password helper).
 *
 * One-time passwords: an admin sets one on new accounts and resets; it's
 * only good for setup, where the user sets up their ways to sign in, and
 * it's discarded once used.
 *
 * The user's own password (the "password" sign-in method, when an admin
 * turned it on): chosen by the user, at least USER_MIN_LENGTH characters,
 * not a commonly used password (app/data/common-passwords.txt, the long
 * ones from the NCSC's 100,000 most used) and not their username.
 */
class PasswordService
{
    public const MIN_LENGTH = 8;

    public const USER_MIN_LENGTH = 12;

    public const USER_MAX_LENGTH = 1024;

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
     * Whether $password is the user's own password.
     */
    public function verifyUserPassword(User $user, string $password): bool
    {
        return $user->login_password !== null && Password::verify($password, $user->login_password);
    }

    /**
     * Why $password can't be the user's password, or null if it can.
     */
    public function userPolicyError(User $user, string $password): ?string
    {
        $length = mb_strlen($password);

        if ($length < self::USER_MIN_LENGTH) {
            return 'Passwords must be at least ' . self::USER_MIN_LENGTH . ' characters. A few unrelated words make a good one.';
        }

        if ($length > self::USER_MAX_LENGTH) {
            return 'Passwords can be at most ' . self::USER_MAX_LENGTH . ' characters.';
        }

        $lower = mb_strtolower($password);

        if (count(array_unique(mb_str_split($lower))) < 4) {
            return 'That password repeats too few characters.';
        }

        if (str_contains($lower, mb_strtolower($user->username))) {
            return "Passwords can't contain your username.";
        }

        if (isset($this->commonPasswords()[$lower])) {
            return 'That password is on a list of commonly used ones. Choose another.';
        }

        return null;
    }

    public function setUserPassword(User $user, string $password): void
    {
        $user->login_password = $this->hash($password);
        $user->password_changed_at = \Carbon\Carbon::now();
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

    /**
     * @return array<string, true>
     */
    private function commonPasswords(): array
    {
        static $list = null;

        if ($list === null) {
            $lines = file(dirname(__DIR__) . '/data/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $list = array_fill_keys(array_map('mb_strtolower', $lines), true);
        }

        return $list;
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= $this->hash(random_bytes(16));
    }
}
