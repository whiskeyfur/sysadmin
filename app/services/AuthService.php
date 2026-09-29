<?php

namespace App\Services;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Models\User;

/**
 * Sign-in, first-login setup and password changes.
 *
 * A sign-in needs the username, the password and a code from the user's
 * authenticator. New accounts and admin resets have no authenticator and
 * a temporary password, so they can only reach setup (new password +
 * authenticator), never the app. Every check is rate limited before any
 * password hashing happens.
 */
class AuthService
{
    public const DEFAULT_ADMIN_USERNAME = 'admin';

    public const DEFAULT_ADMIN_PASSWORD = 'changeme';

    public function __construct(
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly TotpService $totp = new TotpService(),
        private readonly SecretCipher $cipher = new SecretCipher(),
        private readonly LoginThrottleService $throttle = new LoginThrottleService(),
    ) {
    }

    /**
     * On a fresh install (no users at all), create the default admin. They
     * must set a new password and enrol an authenticator at first sign-in.
     */
    public function ensureDefaultAdmin(): void
    {
        if (User::query()->exists()) {
            return;
        }

        $admin = new User(['username' => self::DEFAULT_ADMIN_USERNAME, 'role' => User::ROLE_ADMIN]);
        $this->passwords->setTemporaryPassword($admin, self::DEFAULT_ADMIN_PASSWORD);
        $admin->save();
    }

    public function attempt(string $username, string $password, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $username, function () use ($username, $password, $code) {
            $user = $this->checkPassword($username, $password);

            if ($user === null) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            if ($this->needsSetup($user)) {
                return new LoginResult(LoginStatus::NeedsSetup, $user);
            }

            if (!$this->consumeCode($user, $code)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            return new LoginResult(LoginStatus::Success, $user);
        });
    }

    /**
     * First sign-in or after an admin reset: choose a password and enrol an
     * authenticator, then sign in.
     */
    public function completeSetup(string $username, string $currentPassword, string $newPassword, string $totpSecret, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $username, function () use ($username, $currentPassword, $newPassword, $totpSecret, $code) {
            // Check the new authenticator first: a wrong code then says nothing about the password.
            $step = $this->totp->verify($totpSecret, $code);

            if ($step === null) {
                return new LoginResult(LoginStatus::InvalidCode);
            }

            $user = $this->checkPassword($username, $currentPassword);

            // Only accounts that need setup: otherwise a password alone could replace the authenticator.
            if ($user === null || !$this->needsSetup($user)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            $policyError = $this->passwords->policyError($newPassword, $currentPassword);

            if ($policyError !== null) {
                return new LoginResult(LoginStatus::PasswordRejected, $user, message: $policyError);
            }

            $this->passwords->setChosenPassword($user, $newPassword);
            $user->totp_secret = $this->cipher->encrypt($totpSecret, $this->totpContext($user));
            $user->totp_last_step = $step;
            $user->save();

            return new LoginResult(LoginStatus::Success, $user);
        });
    }

    /**
     * A signed-in user changing their password, voluntarily or because it
     * expired. Ends their other sessions; the caller restarts this one.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, string $ip): LoginResult
    {
        return $this->throttled($ip, $user->username, function () use ($user, $currentPassword, $newPassword) {
            if (!$this->passwords->verify($user, $currentPassword)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            $policyError = $this->passwords->policyError($newPassword, $currentPassword);

            if ($policyError !== null) {
                return new LoginResult(LoginStatus::PasswordRejected, $user, message: $policyError);
            }

            $this->passwords->setChosenPassword($user, $newPassword);
            $user->session_version = $user->session_version + 1;
            $user->save();

            return new LoginResult(LoginStatus::Success, $user);
        });
    }

    public function needsSetup(User $user): bool
    {
        return $user->must_change_password || !$user->hasAuthenticator();
    }

    /**
     * Refuse the request if the IP or username is rate limited; otherwise run
     * it and count a failure, or clear the username's failures on success.
     *
     * @param callable(): LoginResult $action
     */
    private function throttled(string $ip, string $username, callable $action): LoginResult
    {
        $retryAfter = $this->throttle->loginRetryAfter($ip, $username);

        if ($retryAfter > 0) {
            return new LoginResult(LoginStatus::TooManyAttempts, retryAfter: $retryAfter);
        }

        $result = $action();

        if (in_array($result->status, [LoginStatus::InvalidCredentials, LoginStatus::InvalidCode], true)) {
            $this->throttle->recordLoginFailure($ip, $username);
        } elseif ($result->status === LoginStatus::Success) {
            $this->throttle->recordLoginSuccess($username);
        }

        return $result;
    }

    /**
     * The user if the password matches, else null. Unknown usernames take as
     * long as wrong passwords.
     */
    private function checkPassword(string $username, string $password): ?User
    {
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->passwords->verifyAgainstNothing($password);

            return null;
        }

        return $this->passwords->verify($user, $password) ? $user : null;
    }

    /**
     * Verify a code and record its time step so it can't be used again.
     */
    private function consumeCode(User $user, string $code): bool
    {
        $secret = $this->cipher->decrypt((string) $user->totp_secret, $this->totpContext($user));
        $step = $this->totp->verify($secret, $code, $user->totp_last_step);

        if ($step === null) {
            return false;
        }

        $user->totp_last_step = $step;
        $user->save();

        return true;
    }

    private function totpContext(User $user): string
    {
        return 'totp-secret:' . $user->id;
    }
}
