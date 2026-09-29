<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\User;
use DomainException;

/**
 * Admin user management (CLAUDE.md): approving or rejecting registrations,
 * promoting and demoting admins under the two-admin rule, password resets,
 * deleting users (which rotates the master key) and manual rotation.
 *
 * Every method takes the acting admin and their master key and checks the
 * admin role itself. Admins can't act on their own account here.
 */
class UserAdminService
{
    public const MINIMUM_ADMINS = 2;

    private const TEMPORARY_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private readonly VaultService $vault;

    private readonly UserKeyService $keys;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
        ?UserKeyService $keys = null,
        private readonly LoginThrottleService $throttle = new LoginThrottleService(),
    ) {
        $this->vault = $vault ?? new VaultService($crypto);
        $this->keys = $keys ?? new UserKeyService($crypto, $this->vault);
    }

    /**
     * @return list<array{user: User, role: string}>
     */
    public function listUsers(User $admin, string $adminMasterKey): array
    {
        $dataKey = $this->keys->requireAdmin($admin, $adminMasterKey);

        try {
            return User::query()->get()
                ->sortBy('username')
                ->map(fn (User $user) => ['user' => $user, 'role' => $this->keys->role($user, $dataKey)])
                ->values()
                ->all();
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    /**
     * Approve a pending registration as a regular user or an admin.
     *
     * @throws DomainException if the target is not pending.
     */
    public function approve(User $admin, string $adminMasterKey, User $target, string $role = UserKeyService::ROLE_USER): void
    {
        if (!in_array($role, [UserKeyService::ROLE_USER, UserKeyService::ROLE_ADMIN], true)) {
            throw new DomainException('Users can only be approved as a user or an admin.');
        }

        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target, $role) {
            $this->requireRole($target, $dataKey, UserKeyService::ROLE_PENDING, 'Only pending registrations can be approved or rejected.');
            $this->keys->setRole($target, $role, $dataKey);
            $target->save();
        });
    }

    /**
     * Delete a pending registration. No rotation: they never signed in, but
     * they did see the key file, so rotate manually if that matters.
     *
     * @throws DomainException if the target is not pending.
     */
    public function reject(User $admin, string $adminMasterKey, User $target): void
    {
        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target) {
            $this->requireRole($target, $dataKey, UserKeyService::ROLE_PENDING, 'Only pending registrations can be approved or rejected.');
            $target->delete();
        });
    }

    /**
     * @throws DomainException if the target is not a regular user.
     */
    public function promote(User $admin, string $adminMasterKey, User $target): void
    {
        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target) {
            $this->requireRole($target, $dataKey, UserKeyService::ROLE_USER, 'Only approved users can be made admins.');
            $this->keys->setRole($target, UserKeyService::ROLE_ADMIN, $dataKey);
            $target->save();
        });
    }

    /**
     * @throws DomainException if the target is not an admin, or it would leave fewer than two admins.
     */
    public function demote(User $admin, string $adminMasterKey, User $target): void
    {
        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target) {
            $this->requireRole($target, $dataKey, UserKeyService::ROLE_ADMIN, 'That user is not an admin.');
            $this->requireAdminsLeftAfterRemoving($dataKey);
            $this->keys->setRole($target, UserKeyService::ROLE_USER, $dataKey);
            $target->save();
        });
    }

    /**
     * Give an approved user a random temporary password, remove their
     * authenticator and end their sessions. Returns the temporary password
     * for the admin to pass on outside the website.
     *
     * @throws DomainException if the target is pending.
     */
    public function resetPassword(User $admin, string $adminMasterKey, User $target): string
    {
        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target) {
            if ($this->keys->role($target, $dataKey) === UserKeyService::ROLE_PENDING) {
                throw new DomainException('Approve or reject a pending registration instead of resetting it.');
            }
        });

        $temporaryPassword = $this->temporaryPassword();
        $this->keys->resetPassword($admin, $adminMasterKey, $target, $temporaryPassword);

        return $temporaryPassword;
    }

    /**
     * Delete an approved user and rotate the master key, since they have
     * seen it (CLAUDE.md rule 6). Needs the acting admin's password to
     * re-wrap their own copy. Returns the new master key; the caller must
     * re-seal the admin's session with it and offer the new key file.
     *
     * @throws InvalidCredentialsException if the admin's password is wrong.
     * @throws TooManyAttemptsException
     * @throws DomainException if the target is pending, or it would leave fewer than two admins.
     */
    public function deleteUser(User $admin, string $adminMasterKey, User $target, string $adminPassword, string $ip): string
    {
        $this->withAdmin($admin, $adminMasterKey, $target, function (string $dataKey) use ($target) {
            $role = $this->keys->role($target, $dataKey);

            if ($role === UserKeyService::ROLE_PENDING) {
                throw new DomainException('Reject pending registrations instead of deleting them.');
            }

            if ($role === UserKeyService::ROLE_ADMIN) {
                $this->requireAdminsLeftAfterRemoving($dataKey);
            }
        });

        return $this->withPasswordCheck($admin, $ip, fn () => $this->transaction(function () use ($admin, $adminPassword, $target) {
            $target->delete();

            return $this->keys->rotateMasterKey($admin, $adminPassword);
        }));
    }

    /**
     * Replace the master key without deleting anyone. Returns the new key.
     *
     * @throws InvalidCredentialsException
     * @throws TooManyAttemptsException
     */
    public function rotateMasterKey(User $admin, string $adminPassword, string $ip): string
    {
        return $this->withPasswordCheck($admin, $ip, fn () => $this->keys->rotateMasterKey($admin, $adminPassword));
    }

    /**
     * Number of admins, readable by any signed-in user for the setup warning.
     */
    public function adminCount(string $masterKey): int
    {
        $dataKey = $this->vault->unwrapDataKey($masterKey);

        try {
            return $this->countAdmins($dataKey);
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    public function needsSecondAdmin(string $masterKey): bool
    {
        return $this->adminCount($masterKey) < self::MINIMUM_ADMINS;
    }

    /**
     * Check the acting admin, refuse self-targeting, and run $action with the
     * data key, wiping it afterwards.
     *
     * @param callable(string): void $action
     */
    private function withAdmin(User $admin, string $adminMasterKey, User $target, callable $action): void
    {
        $dataKey = $this->keys->requireAdmin($admin, $adminMasterKey);

        try {
            if ($admin->id === $target->id) {
                throw new DomainException("You can't change your own account here.");
            }

            $action($dataKey);
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    /**
     * Rate-limit a password re-entry by the acting admin like a sign-in.
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private function withPasswordCheck(User $admin, string $ip, callable $action): mixed
    {
        $retryAfter = $this->throttle->loginRetryAfter($ip, $admin->username);

        if ($retryAfter > 0) {
            throw new TooManyAttemptsException($retryAfter);
        }

        try {
            return $action();
        } catch (InvalidCredentialsException $e) {
            $this->throttle->recordLoginFailure($ip, $admin->username);

            throw $e;
        }
    }

    private function requireRole(User $target, string $dataKey, string $role, string $message): void
    {
        if ($this->keys->role($target, $dataKey) !== $role) {
            throw new DomainException($message);
        }
    }

    private function requireAdminsLeftAfterRemoving(string $dataKey): void
    {
        if ($this->countAdmins($dataKey) - 1 < self::MINIMUM_ADMINS) {
            throw new DomainException('There must always be at least ' . self::MINIMUM_ADMINS . ' admins. Make someone else an admin first.');
        }
    }

    private function countAdmins(string $dataKey): int
    {
        return User::query()->get()
            ->filter(fn (User $user) => $this->keys->isAdmin($user, $dataKey))
            ->count();
    }

    private function temporaryPassword(): string
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
