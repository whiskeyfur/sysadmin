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
 * user_data holds {master_key, must_change_password}, encrypted with a key
 * derived from the user's password. role holds "admin" or "user", encrypted
 * with the data key so any unlocked admin can read it.
 */
class UserKeyService
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    private readonly VaultService $vault;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
    ) {
        $this->vault = $vault ?? new VaultService($crypto);
    }

    /**
     * Bootstrap: create the vault and the first user, who is an admin.
     * Returns the new master key for the caller to offer as a key file.
     *
     * @throws VaultStateException if the vault already exists.
     */
    public function createFirstAdmin(string $username, string $password): string
    {
        return $this->transaction(function () use ($username, $password) {
            $masterKey = $this->vault->initialize();
            $dataKey = $this->vault->unwrapDataKey($masterKey);

            $user = new User(['username' => $username]);
            $user->role = $this->encryptRole($username, self::ROLE_ADMIN, $dataKey);
            $this->writeUserData($user, $password, $masterKey, false);
            $user->save();

            $this->crypto->wipe($dataKey);

            return $masterKey;
        });
    }

    /**
     * Create a regular user from an uploaded key file's master key.
     *
     * @throws InvalidMasterKeyException if the key does not unlock the vault.
     */
    public function register(string $username, string $password, string $masterKey): User
    {
        $dataKey = $this->vault->unwrapDataKey($masterKey);

        $user = new User(['username' => $username]);
        $user->role = $this->encryptRole($username, self::ROLE_USER, $dataKey);
        $this->writeUserData($user, $password, $masterKey, false);
        $user->save();

        $this->crypto->wipe($dataKey);

        return $user;
    }

    /**
     * Log in: unwrap the master key and confirm it still unlocks the vault.
     *
     * @throws InvalidCredentialsException if the password is wrong.
     * @throws StaleMasterKeyException if the master key was rotated; the user must upload the new key file.
     */
    public function unlock(User $user, string $password): UnlockedUser
    {
        $unlocked = $this->readUserData($user, $password);

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
     * Replace a user's stored master key with one from an uploaded key file,
     * for example after a rotation left their copy stale.
     *
     * @throws InvalidCredentialsException
     * @throws InvalidMasterKeyException if the new key does not unlock the vault.
     */
    public function replaceMasterKey(User $user, string $password, string $newMasterKey): void
    {
        $this->vault->verifyMasterKey($newMasterKey);

        $current = $this->readUserData($user, $password);
        $this->writeUserData($user, $password, $newMasterKey, $current->mustChangePassword);
        $current->wipe();

        $user->save();
    }

    /**
     * Re-wrap the user's master key under a new password. Clears the
     * must-change flag set by an admin reset.
     *
     * @throws InvalidCredentialsException
     * @throws StaleMasterKeyException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        $unlocked = $this->unlock($user, $currentPassword);
        $this->writeUserData($user, $newPassword, (string) $unlocked->masterKey, false);
        $unlocked->wipe();

        $user->save();
    }

    /**
     * Admin-only password reset. The target gets a temporary password they
     * must change at next login.
     *
     * @throws InvalidMasterKeyException if the admin's master key does not unlock the vault.
     * @throws AuthorizationException if $admin is not an admin.
     */
    public function resetPassword(User $admin, string $adminMasterKey, User $target, string $temporaryPassword): void
    {
        $dataKey = $this->vault->unwrapDataKey($adminMasterKey);

        try {
            if (!$this->isAdmin($admin, $dataKey)) {
                throw new AuthorizationException('Only admins can reset passwords.');
            }
        } finally {
            $this->crypto->wipe($dataKey);
        }

        $this->writeUserData($target, $temporaryPassword, $adminMasterKey, true);
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
            $dataKey = $this->vault->unwrapDataKey((string) $unlocked->masterKey);

            try {
                if (!$this->isAdmin($user, $dataKey)) {
                    $unlocked->wipe();

                    throw new AuthorizationException('Only admins can rotate the master key.');
                }
            } finally {
                $this->crypto->wipe($dataKey);
            }

            $newMasterKey = $this->vault->rotateMasterKey((string) $unlocked->masterKey);

            $this->writeUserData($user, $password, $newMasterKey, $unlocked->mustChangePassword);
            $unlocked->wipe();
            $user->save();

            return $newMasterKey;
        });
    }

    /**
     * @throws DecryptionException if the role column was tampered with or copied from another user.
     */
    public function isAdmin(User $user, string $dataKey): bool
    {
        return $this->crypto->decrypt($user->role, $dataKey, $this->roleContext($user->username)) === self::ROLE_ADMIN;
    }

    private function readUserData(User $user, string $password): UnlockedUser
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

        return new UnlockedUser($masterKey, (bool) ($data['must_change_password'] ?? false));
    }

    /**
     * Encrypt the user-data field under a fresh salt with the current KDF
     * limits. Sets attributes only; the caller saves.
     */
    private function writeUserData(User $user, string $password, string $masterKey, bool $mustChangePassword): void
    {
        $salt = $this->crypto->generateSalt();
        $wrappingKey = $this->crypto->deriveKey($password, $salt, $this->crypto->opsLimit(), $this->crypto->memLimit());

        $json = json_encode([
            'master_key' => $this->crypto->encode($masterKey),
            'must_change_password' => $mustChangePassword,
        ], JSON_THROW_ON_ERROR);

        $user->kdf_salt = $this->crypto->encode($salt);
        $user->kdf_opslimit = $this->crypto->opsLimit();
        $user->kdf_memlimit = $this->crypto->memLimit();
        $user->user_data = $this->crypto->encrypt($json, $wrappingKey, $this->userDataContext($user->username));

        $this->crypto->wipe($wrappingKey, $json);
    }

    private function encryptRole(string $username, string $role, string $dataKey): string
    {
        return $this->crypto->encrypt($role, $dataKey, $this->roleContext($username));
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
