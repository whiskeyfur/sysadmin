<?php

namespace App\Services;

use App\Models\User;
use DomainException;

/**
 * Admin user management: approving or rejecting registrations and the
 * two-admin rule (CLAUDE.md). Every method takes the acting admin and their
 * master key, and checks the admin role itself.
 */
class UserAdminService
{
    public const MINIMUM_ADMINS = 2;

    private readonly VaultService $vault;

    private readonly UserKeyService $keys;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
        ?UserKeyService $keys = null,
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

        $dataKey = $this->keys->requireAdmin($admin, $adminMasterKey);

        try {
            $this->requirePending($target, $dataKey);
            $this->keys->setRole($target, $role, $dataKey);
            $target->save();
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    /**
     * Delete a pending registration.
     *
     * @throws DomainException if the target is not pending.
     */
    public function reject(User $admin, string $adminMasterKey, User $target): void
    {
        $dataKey = $this->keys->requireAdmin($admin, $adminMasterKey);

        try {
            $this->requirePending($target, $dataKey);
            $target->delete();
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    /**
     * Number of admins, readable by any signed-in user for the setup warning.
     */
    public function adminCount(string $masterKey): int
    {
        $dataKey = $this->vault->unwrapDataKey($masterKey);

        try {
            return User::query()->get()
                ->filter(fn (User $user) => $this->keys->isAdmin($user, $dataKey))
                ->count();
        } finally {
            $this->crypto->wipe($dataKey);
        }
    }

    public function needsSecondAdmin(string $masterKey): bool
    {
        return $this->adminCount($masterKey) < self::MINIMUM_ADMINS;
    }

    private function requirePending(User $target, string $dataKey): void
    {
        if ($this->keys->role($target, $dataKey) !== UserKeyService::ROLE_PENDING) {
            throw new DomainException('Only pending registrations can be approved or rejected.');
        }
    }
}
