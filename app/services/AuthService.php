<?php

namespace App\Services;

use App\DTOs\LoginResult;
use App\DTOs\UnlockedUser;
use App\Enums\LoginStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\InvalidKeyFileException;
use App\Exceptions\InvalidMasterKeyException;
use App\Exceptions\VaultStateException;
use App\Models\User;

/**
 * The sign-in flows. A login needs the username, the password and a code
 * from the user's authenticator; an account with no authenticator can only
 * reach setup, never the app.
 *
 * Order of checks: password, then authenticator code, then master key
 * against the vault (stale?), then role (pending?).
 */
class AuthService
{
    public const DEFAULT_ADMIN_USERNAME = 'admin';

    public const DEFAULT_ADMIN_PASSWORD = 'changeme';

    private readonly VaultService $vault;

    private readonly UserKeyService $keys;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
        ?UserKeyService $keys = null,
        private readonly TotpService $totp = new TotpService(),
        private readonly KeyFileService $keyFiles = new KeyFileService(),
    ) {
        $this->vault = $vault ?? new VaultService($crypto);
        $this->keys = $keys ?? new UserKeyService($crypto, $this->vault);
    }

    /**
     * On a fresh install, create the default admin. They must set a new
     * password and enrol an authenticator at first login.
     */
    public function ensureDefaultAdmin(): void
    {
        if ($this->vault->isInitialized()) {
            return;
        }

        try {
            $masterKey = $this->keys->createFirstAdmin(self::DEFAULT_ADMIN_USERNAME, self::DEFAULT_ADMIN_PASSWORD, true);
            $this->crypto->wipe($masterKey);
        } catch (VaultStateException) {
            // Another request initialized the vault first.
        }
    }

    public function attempt(string $username, string $password, string $code): LoginResult
    {
        $user = $this->findUser($username);

        if ($user === null) {
            $this->spendPasswordHashTime($password);

            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        try {
            $unlocked = $this->keys->decryptUserData($user, $password);
        } catch (InvalidCredentialsException) {
            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        if (!$unlocked->hasAuthenticator() || $unlocked->mustChangePassword) {
            $unlocked->wipe();

            return new LoginResult(LoginStatus::NeedsSetup, $user);
        }

        if (!$this->consumeCode($user, (string) $unlocked->totpSecret, $code)) {
            $unlocked->wipe();

            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        return $this->finishLogin($user, $unlocked);
    }

    /**
     * Set a new password and enrol an authenticator, then sign in.
     */
    public function completeSetup(string $username, string $currentPassword, string $newPassword, string $totpSecret, string $code): LoginResult
    {
        // Check the new authenticator first: a wrong code then says nothing about the password.
        $step = $this->totp->verify($totpSecret, $code);

        if ($step === null) {
            return new LoginResult(LoginStatus::InvalidCode);
        }

        $user = $this->findUser($username);

        if ($user === null) {
            $this->spendPasswordHashTime($currentPassword);

            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        try {
            $this->keys->completeSetup($user, $currentPassword, $newPassword, $totpSecret, $step);

            return $this->finishLogin($user, $this->keys->decryptUserData($user, $newPassword));
        } catch (InvalidCredentialsException | AuthorizationException) {
            return new LoginResult(LoginStatus::InvalidCredentials);
        }
    }

    /**
     * Upload the current key file after a rotation, then sign in.
     */
    public function replaceKey(string $username, string $password, string $code, string $keyFile): LoginResult
    {
        $user = $this->findUser($username);

        if ($user === null) {
            $this->spendPasswordHashTime($password);

            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        try {
            $unlocked = $this->keys->decryptUserData($user, $password);
        } catch (InvalidCredentialsException) {
            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        if (!$unlocked->hasAuthenticator() || $unlocked->mustChangePassword) {
            $unlocked->wipe();

            return new LoginResult(LoginStatus::NeedsSetup, $user);
        }

        $codeAccepted = $this->consumeCode($user, (string) $unlocked->totpSecret, $code);
        $unlocked->wipe();

        if (!$codeAccepted) {
            return new LoginResult(LoginStatus::InvalidCredentials);
        }

        try {
            $masterKey = $this->keyFiles->parse($keyFile);
            $this->keys->replaceMasterKey($user, $password, $masterKey);
            $this->crypto->wipe($masterKey);
        } catch (InvalidKeyFileException | InvalidMasterKeyException) {
            return new LoginResult(LoginStatus::InvalidKeyFile, $user);
        }

        return $this->finishLogin($user, $this->keys->decryptUserData($user, $password));
    }

    /**
     * Create a pending account. Needs the key file and a working authenticator.
     */
    public function register(string $username, string $password, string $keyFile, string $totpSecret, string $code): LoginResult
    {
        $step = $this->totp->verify($totpSecret, $code);

        if ($step === null) {
            return new LoginResult(LoginStatus::InvalidCode);
        }

        if ($this->findUser($username) !== null) {
            return new LoginResult(LoginStatus::UsernameTaken);
        }

        try {
            $masterKey = $this->keyFiles->parse($keyFile);
            $user = $this->keys->register($username, $password, $masterKey, $totpSecret, $step);
            $this->crypto->wipe($masterKey);
        } catch (InvalidKeyFileException | InvalidMasterKeyException) {
            return new LoginResult(LoginStatus::InvalidKeyFile);
        }

        return new LoginResult(LoginStatus::Pending, $user);
    }

    private function finishLogin(User $user, UnlockedUser $unlocked): LoginResult
    {
        $masterKey = (string) $unlocked->masterKey;
        $unlocked->wipe();

        try {
            $dataKey = $this->vault->unwrapDataKey($masterKey);
        } catch (InvalidMasterKeyException) {
            $this->crypto->wipe($masterKey);

            return new LoginResult(LoginStatus::StaleKey, $user);
        }

        $role = $this->keys->role($user, $dataKey);
        $this->crypto->wipe($dataKey);

        if ($role === UserKeyService::ROLE_PENDING) {
            $this->crypto->wipe($masterKey);

            return new LoginResult(LoginStatus::Pending, $user);
        }

        return new LoginResult(LoginStatus::Success, $user, $masterKey);
    }

    /**
     * Verify a code and record its time step so it can't be used again.
     */
    private function consumeCode(User $user, string $totpSecret, string $code): bool
    {
        $step = $this->totp->verify($totpSecret, $code, $user->totp_last_step);

        if ($step === null) {
            return false;
        }

        $user->totp_last_step = $step;
        $user->save();

        return true;
    }

    private function findUser(string $username): ?User
    {
        return User::query()->where('username', $username)->first();
    }

    /**
     * Run one password derivation for an unknown username, so its response
     * takes as long as a wrong password for a real one.
     */
    private function spendPasswordHashTime(string $password): void
    {
        $key = $this->crypto->deriveKey($password, $this->crypto->generateSalt(), $this->crypto->opsLimit(), $this->crypto->memLimit());
        $this->crypto->wipe($key);
    }
}
