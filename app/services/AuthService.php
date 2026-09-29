<?php

namespace App\Services;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Models\User;

/**
 * Sign-in and first-login setup.
 *
 * A sign-in needs the username and a code from the user's authenticator;
 * there are no user passwords. New accounts and admin resets have no
 * authenticator, only a one-time password the admin set, which opens setup
 * (enrol an authenticator) and nothing else. Every check is rate limited
 * before any hashing or code checking happens.
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
     * On a fresh install (no users at all), create the default admin with
     * the one-time password "changeme". Also forgets passwords left from
     * before sign-in was code-only.
     */
    public function ensureDefaultAdmin(): void
    {
        User::query()->where('must_change_password', false)->whereNotNull('password')->update(['password' => null]);

        if (User::query()->exists()) {
            return;
        }

        $admin = new User(['username' => self::DEFAULT_ADMIN_USERNAME, 'role' => User::ROLE_ADMIN]);
        $this->passwords->setOneTimePassword($admin, self::DEFAULT_ADMIN_PASSWORD);
        $admin->save();
    }

    public function attempt(string $username, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $username, function () use ($username, $code) {
            $user = User::query()->where('username', $username)->first();

            if ($user === null || $this->needsSetup($user) || !$this->consumeCode($user, $code)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            return new LoginResult(LoginStatus::Success, $user);
        });
    }

    /**
     * Check a one-time password. NeedsSetup (with the user) means the caller
     * may show setup for that user.
     */
    public function startSetup(string $username, string $oneTimePassword, string $ip): LoginResult
    {
        return $this->throttled($ip, $username, function () use ($username, $oneTimePassword) {
            $user = User::query()->where('username', $username)->first();

            if ($user === null) {
                $this->passwords->verifyAgainstNothing($oneTimePassword);

                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            if (!$user->must_change_password || !$this->passwords->verify($user, $oneTimePassword)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            return new LoginResult(LoginStatus::NeedsSetup, $user);
        });
    }

    /**
     * Enrol the authenticator the user was shown, discard the one-time
     * password and sign in. $sessionVersion is the user's version when setup
     * started: an admin reset in between invalidates the setup.
     */
    public function completeSetup(User $user, int $sessionVersion, string $totpSecret, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $user->username, function () use ($user, $sessionVersion, $totpSecret, $code) {
            $user = $user->fresh();

            if (!$user instanceof User || !$user->must_change_password || $user->session_version !== $sessionVersion) {
                return new LoginResult(LoginStatus::SetupExpired);
            }

            $step = $this->totp->verify($totpSecret, $code);

            if ($step === null) {
                return new LoginResult(LoginStatus::InvalidCode);
            }

            $this->passwords->discard($user);
            $user->totp_secret = $this->cipher->encrypt($totpSecret, $this->totpContext($user));
            $user->totp_last_step = $step;
            $user->save();

            return new LoginResult(LoginStatus::Success, $user);
        });
    }

    /**
     * A signed-in user proving it's them again (e.g. to reveal a password),
     * rate limited like a sign-in.
     */
    public function confirm(User $user, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $user->username, function () use ($user, $code) {
            if ($this->needsSetup($user) || !$this->consumeCode($user, $code)) {
                return new LoginResult(LoginStatus::InvalidCode);
            }

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
     * Verify a code and record its time step so it can't be used again.
     */
    private function consumeCode(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

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
