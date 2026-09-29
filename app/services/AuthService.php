<?php

namespace App\Services;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Exceptions\InvalidCredentialsException;
use App\Models\Passkey;
use App\Models\User;

/**
 * Sign-in, first-login setup and re-confirming.
 *
 * A sign-in takes the username and any one method the admin turned on
 * that the user has set up (LoginMethodService): their password, an
 * authenticator code or a passkey. New accounts and admin resets have
 * none, only a one-time password the admin set, which opens setup
 * (Profile: set up the required methods) and nothing else. Every check is
 * rate limited before any hashing or code checking happens.
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
        private readonly LoginMethodService $methods = new LoginMethodService(),
        private readonly PasskeyService $passkeys = new PasskeyService(),
    ) {
    }

    /**
     * On a fresh install (no users at all), create the default admin with
     * the one-time password "changeme". Also forgets one-time passwords
     * left on accounts that finished setup.
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

    /**
     * Sign in with a password or an authenticator code (give one).
     */
    public function attempt(string $username, string $password, string $code, string $ip): LoginResult
    {
        return $this->throttled($ip, $username, function () use ($username, $password, $code) {
            $user = User::query()->where('username', $username)->first();
            $ok = $password !== ''
                ? $this->checkPassword($user, $password)
                : $user instanceof User && !$this->needsSetup($user) && $this->methods->isEnabled(LoginMethodService::AUTHENTICATOR) && $user->hasAuthenticator() && $this->consumeCode($user, $code);

            return $ok && $user instanceof User ? new LoginResult(LoginStatus::Success, $user) : new LoginResult(LoginStatus::InvalidCredentials);
        });
    }

    /**
     * Sign in with a passkey (PasskeyService::verify() does the checking).
     *
     * @param array{rp_id: string, origin: string} $site
     * @param array<string, mixed> $credential
     */
    public function attemptPasskey(array $site, string $challenge, array $credential, string $ip): LoginResult
    {
        // Rate limited per user as well: find whose passkey it claims to be (no secret involved).
        $rawId = PasskeyService::decode((string) ($credential['id'] ?? ''));
        $owner = $rawId === '' ? null : Passkey::query()->where('credential_hash', hash('sha256', $rawId))->first()?->user;

        return $this->throttled($ip, $owner->username ?? '', function () use ($site, $challenge, $credential) {
            if (!$this->methods->isEnabled(LoginMethodService::PASSKEY)) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            try {
                $user = $this->passkeys->verify($site, $challenge, $credential)->user;
            } catch (InvalidCredentialsException) {
                return new LoginResult(LoginStatus::InvalidCredentials);
            }

            return $user instanceof User && !$this->needsSetup($user) ? new LoginResult(LoginStatus::Success, $user) : new LoginResult(LoginStatus::InvalidCredentials);
        });
    }

    /**
     * Check a one-time password. NeedsSetup (with the user) means the caller
     * may sign them in to set up their ways to sign in, and nothing else.
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
     * Finish setup once the required methods are set up: the one-time
     * password is discarded and the account works normally.
     *
     * @throws \DomainException when something required is still missing
     */
    public function completeSetup(User $user): void
    {
        if (!$user->must_change_password) {
            return;
        }

        if (!$this->methods->setUpEnough($user)) {
            throw new \DomainException('Set up ' . ($this->methods->missingRequired($user) === [] ? 'a way to sign in' : implode(' and ', array_map(fn ($m) => strtolower(LoginMethodService::LABELS[$m]), $this->methods->missingRequired($user)))) . ' first.');
        }

        $this->passwords->discard($user);
        $user->save();
    }

    /**
     * A signed-in user proving it's them again (e.g. to reveal a password,
     * or to change how they sign in), rate limited like a sign-in, with any
     * method they have: ['password' => ...], ['code' => ...] or
     * ['passkey' => [site, challenge, credential]].
     *
     * @param array<string, mixed> $proof
     */
    public function confirm(User $user, array $proof, string $ip): LoginResult
    {
        return $this->throttled($ip, $user->username, function () use ($user, $proof) {
            if ($this->needsSetup($user)) {
                return new LoginResult(LoginStatus::InvalidCode);
            }

            $ok = match (true) {
                isset($proof['password']) && $proof['password'] !== '' => $this->checkPassword($user, (string) $proof['password']),
                isset($proof['code']) && $proof['code'] !== '' => $this->methods->isEnabled(LoginMethodService::AUTHENTICATOR) && $user->hasAuthenticator() && $this->consumeCode($user, (string) $proof['code']),
                isset($proof['passkey']) && is_array($proof['passkey']) => $this->confirmPasskey($user, $proof['passkey']),
                default => false,
            };

            return $ok ? new LoginResult(LoginStatus::Success, $user) : new LoginResult(LoginStatus::InvalidCode);
        });
    }

    /**
     * Enrol an authenticator: the code must match the new secret.
     *
     * @throws \DomainException when the code is wrong
     */
    public function enrolAuthenticator(User $user, string $secret, string $code): void
    {
        $step = $this->totp->verify($secret, $code);

        if ($step === null) {
            throw new \DomainException("That code doesn't match. Enter the code the app shows now.");
        }

        $user->totp_secret = $this->cipher->encrypt($secret, $this->totpContext($user));
        $user->totp_last_step = $step;
        $user->save();
    }

    public function needsSetup(User $user): bool
    {
        return $user->must_change_password;
    }

    /**
     * The user's own password, if that method is on. Unknown users and users
     * without one still cost a hash check, so they take as long.
     */
    private function checkPassword(?User $user, string $password): bool
    {
        if (!$user instanceof User || $this->needsSetup($user) || !$user->hasPassword() || !$this->methods->isEnabled(LoginMethodService::PASSWORD)) {
            $this->passwords->verifyAgainstNothing($password);

            return false;
        }

        return $this->passwords->verifyUserPassword($user, $password);
    }

    /**
     * @param array<int|string, mixed> $passkey [site, challenge, credential]
     */
    private function confirmPasskey(User $user, array $passkey): bool
    {
        if (!$this->methods->isEnabled(LoginMethodService::PASSKEY) || count($passkey) !== 3) {
            return false;
        }

        try {
            $this->passkeys->verify($passkey[0], (string) $passkey[1], $passkey[2], $user);

            return true;
        } catch (InvalidCredentialsException) {
            return false;
        }
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
