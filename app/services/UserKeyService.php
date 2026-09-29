<?php

namespace App\Services;

use App\DTOs\UnlockedUser;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DecryptionException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\InvalidMasterKeyException;
use App\Exceptions\StaleMasterKeyException;
use App\Exceptions\VaultStateException;
use App\Models\User;
use JsonException;

/**
 * Wraps the master key into each user's encrypted user-data field and
 * unwraps it again at login (CLAUDE.md "Unlock flow" and rules 2-6, 8).
 *
 * user_data holds {master_key, totp_secret, must_change_password}, encrypted
 * with a key derived from the user's password. role holds "pending", "user"
 * or "admin", encrypted with the data key so any unlocked admin can read it.
 */
class UserKeyService
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const ROLE_PENDING = 'pending';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_USER, self::ROLE_PENDING];

    private readonly VaultService $vault;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
    ) {
        $this->vault = $vault ?? new VaultService($crypto);
    }

    /**
     * Bootstrap: create the vault and the first user, who is an admin with
     * no authenticator yet. Returns the new master key.
     *
     * @throws VaultStateException if the vault already exists.
     */
    public function createFirstAdmin(string $username, string $password, bool $mustChangePassword = false): string
    {
        return $this->transaction(function () use ($username, $password, $mustChangePassword) {
            $masterKey = $this->vault->initialize();
            $dataKey = $this->vault->unwrapDataKey($masterKey);

            $user = new User(['username' => $username]);
            $this->setRole($user, self::ROLE_ADMIN, $dataKey);
            $this->writeUserData($user, $password, $masterKey, $mustChangePassword, null);
            $user->save();

            $this->crypto->wipe($dataKey);

            return $masterKey;
        });
    }

    /**
     * Create a user from an uploaded key file's master key. The account is
     * pending until an admin approves it.
     *
     * @throws InvalidMasterKeyException if the key does not unlock the vault.
     */
    public function register(string $username, string $password, string $masterKey, string $totpSecret, ?int $totpStep = null): User
    {
        $dataKey = $this->vault->unwrapDataKey($masterKey);

        $user = new User(['username' => $username]);
        $user->totp_last_step = $totpStep;
        $this->setRole($user, self::ROLE_PENDING, $dataKey);
        $this->writeUserData($user, $password, $masterKey, false, $totpSecret);
        $user->save();

        $this->crypto->wipe($dataKey);

        return $user;
    }

    /**
     * Decrypt the user-data field without checking it against the vault.
     *
     * @throws InvalidCredentialsException if the password is wrong.
     */
    public function decryptUserData(User $user, string $password): UnlockedUser
    {
        $salt = $this->crypto->decode($user->kdf_salt);

        if ($salt === null) {
            throw new DecryptionException('The stored salt is corrupt.');
        }

        $wrappingKey = $this->crypto->deriveKey($password, $salt, $user->kdf_opslimit, $user->kdf_memlimit);

        try {
            $json = $this->crypto->decrypt($user->user_data, $wrappingKey, $this->userDataContext($user->username));
        } catch (DecryptionException $e) {
            throw new InvalidCredentialsException('Invalid username or password.', 0, $e);
        } finally {
            $this->crypto->wipe($wrappingKey);
        }

        try {
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DecryptionException('The user-data field is corrupt.', 0, $e);
        } finally {
            $this->crypto->wipe($json);
        }

        $masterKey = is_string($data['master_key'] ?? null) ? $this->crypto->decode($data['master_key']) : null;

        if ($masterKey === null) {
            throw new DecryptionException('The user-data field is corrupt.');
        }

        return new UnlockedUser(
            $masterKey,
            (bool) ($data['must_change_password'] ?? false),
            is_string($data['totp_secret'] ?? null) ? $data['totp_secret'] : null,
        );
    }

    /**
     * Decrypt the user-data field and confirm the master key still unlocks the vault.
     *
     * @throws InvalidCredentialsException if the password is wrong.
     * @throws StaleMasterKeyException if the master key was rotated; the user must upload the new key file.
     */
    public function unlock(User $user, string $password): UnlockedUser
    {
        $unlocked = $this->decryptUserData($user, $password);

        try {
            $this->vault->verifyMasterKey((string) $unlocked->masterKey);
        } catch (InvalidMasterKeyException $e) {
            $unlocked->wipe();

            throw new StaleMasterKeyException('Your master key is out of date. Upload the current key file.', 0, $e);
        }

        return $unlocked;
    }

    /**
     * @throws InvalidCredentialsException
     * @throws StaleMasterKeyException
     */
    public function getMasterKeyFromUserDataField(User $user, string $password): string
    {
        $unlocked = $this->unlock($user, $password);
        $masterKey = (string) $unlocked->masterKey;
        $unlocked->wipe();

        return $masterKey;
    }

    /**
     * First-login setup for the default admin or after an admin reset: set a
     * new password and enrol an authenticator. Does not check the vault, so a
     * stale user can finish setup and then upload the new key file.
     *
     * Only allowed while the account needs setup; otherwise a password alone
     * could replace an enrolled authenticator.
     *
     * @throws InvalidCredentialsException
     * @throws AuthorizationException if the account does not need setup.
     */
    public function completeSetup(User $user, string $currentPassword, string $newPassword, string $totpSecret, int $totpStep): void
    {
        $unlocked = $this->decryptUserData($user, $currentPassword);

        if ($unlocked->hasAuthenticator() && !$unlocked->mustChangePassword) {
            $unlocked->wipe();

            throw new AuthorizationException('This account is already set up.');
        }
        $this->writeUserData($user, $newPassword, (string) $unlocked->masterKey, false, $totpSecret);
        $unlocked->wipe();

        $user->totp_last_step = $totpStep;
        $user->save();
    }

    /**
     * Replace a user's stored master key with one from an uploaded key file,
     * for example after a rotation left their copy stale.
     *
     * @throws InvalidCredentialsException
     * @throws InvalidMasterKeyException if the new key does not unlock the vault.
     */
    public function replaceMasterKey(User $user, string $password, string $newMasterKey): void
    {
        $this->vault->verifyMasterKey($newMasterKey);

        $current = $this->decryptUserData($user, $password);
        $this->writeUserData($user, $password, $newMasterKey, $current->mustChangePassword, $current->totpSecret);
        $current->wipe();

        $user->save();
    }

    /**
     * Re-wrap the user's master key under a new password.
     *
     * @throws InvalidCredentialsException
     * @throws StaleMasterKeyException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        $unlocked = $this->unlock($user, $currentPassword);
        $this->writeUserData($user, $newPassword, (string) $unlocked->masterKey, false, $unlocked->totpSecret);
        $unlocked->wipe();

        $user->save();
    }

    /**
     * Admin-only password reset. The admin can't read the target's old
     * user-data field, so their authenticator is removed too: at next login
     * they must set a new password and enrol an authenticator again.
     *
     * @throws InvalidMasterKeyException if the admin's master key does not unlock the vault.
     * @throws AuthorizationException if $admin is not an admin.
     */
    public function resetPassword(User $admin, string $adminMasterKey, User $target, string $temporaryPassword): void
    {
        $dataKey = $this->requireAdmin($admin, $adminMasterKey);
        $this->crypto->wipe($dataKey);

        $this->writeUserData($target, $temporaryPassword, $adminMasterKey, true, null);
        $target->totp_last_step = null;
        $target->save();
    }

    /**
     * Admin-only: rotate the master key (for example after removing a user)
     * and re-wrap it for the acting admin. Returns the new master key for the
     * caller to offer as a key file; every other user must upload it.
     *
     * @throws InvalidCredentialsException
     * @throws StaleMasterKeyException
     * @throws AuthorizationException if $user is not an admin.
     */
    public function rotateMasterKey(User $user, string $password): string
    {
        return $this->transaction(function () use ($user, $password) {
            $unlocked = $this->unlock($user, $password);

            try {
                $dataKey = $this->requireAdmin($user, (string) $unlocked->masterKey);
                $this->crypto->wipe($dataKey);
            } catch (AuthorizationException $e) {
                $unlocked->wipe();

                throw new AuthorizationException('Only admins can rotate the master key.', 0, $e);
            }

            $newMasterKey = $this->vault->rotateMasterKey((string) $unlocked->masterKey);

            $this->writeUserData($user, $password, $newMasterKey, $unlocked->mustChangePassword, $unlocked->totpSecret);
            $unlocked->wipe();
            $user->save();

            return $newMasterKey;
        });
    }

    /**
     * @throws DecryptionException if the role column was tampered with or copied from another user.
     */
    public function role(User $user, string $dataKey): string
    {
        return $this->crypto->decrypt($user->role, $dataKey, $this->roleContext($user->username));
    }

    /**
     * Set the encrypted role attribute. The caller checks authorization and saves.
     */
    public function setRole(User $user, string $role, string $dataKey): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown role: $role");
        }

        $user->role = $this->crypto->encrypt($role, $dataKey, $this->roleContext($user->username));
    }

    /**
     * @throws DecryptionException
     */
    public function isAdmin(User $user, string $dataKey): bool
    {
        return $this->role($user, $dataKey) === self::ROLE_ADMIN;
    }

    /**
     * Confirm $admin is an admin and return the data key, which the caller
     * must wipe.
     *
     * @throws InvalidMasterKeyException
     * @throws AuthorizationException
     */
    public function requireAdmin(User $admin, string $adminMasterKey): string
    {
        $dataKey = $this->vault->unwrapDataKey($adminMasterKey);

        if (!$this->isAdmin($admin, $dataKey)) {
            $this->crypto->wipe($dataKey);

            throw new AuthorizationException('Only admins can do this.');
        }

        return $dataKey;
    }

    /**
     * Encrypt the user-data field under a fresh salt with the current KDF
     * limits. Sets attributes only; the caller saves.
     */
    private function writeUserData(User $user, string $password, string $masterKey, bool $mustChangePassword, ?string $totpSecret): void
    {
        $salt = $this->crypto->generateSalt();
        $wrappingKey = $this->crypto->deriveKey($password, $salt, $this->crypto->opsLimit(), $this->crypto->memLimit());

        $json = json_encode([
            'master_key' => $this->crypto->encode($masterKey),
            'totp_secret' => $totpSecret,
            'must_change_password' => $mustChangePassword,
        ], JSON_THROW_ON_ERROR);

        $user->kdf_salt = $this->crypto->encode($salt);
        $user->kdf_opslimit = $this->crypto->opsLimit();
        $user->kdf_memlimit = $this->crypto->memLimit();
        $user->user_data = $this->crypto->encrypt($json, $wrappingKey, $this->userDataContext($user->username));

        $this->crypto->wipe($wrappingKey, $json);
    }

    private function userDataContext(string $username): string
    {
        return 'user-data:' . $username;
    }

    private function roleContext(string $username): string
    {
        return 'user-role:' . $username;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return (new User())->getConnection()->transaction($callback);
    }
}
