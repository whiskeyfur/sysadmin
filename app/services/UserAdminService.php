<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Models\User;
use DomainException;

/**
 * Admin user management. Accounts are created by admins only, with a random
 * temporary password the admin hands out; the user sets their own password
 * and authenticator at first sign-in.
 *
 * Every method re-checks that the acting user is an admin and refuses
 * actions on their own account. There must always be at least one admin.
 */
class UserAdminService
{
    public const MINIMUM_ADMINS = 1;

    public function __construct(private readonly PasswordService $passwords = new PasswordService())
    {
    }

    /**
     * @return list<User>
     */
    public function listUsers(User $admin): array
    {
        $this->requireAdmin($admin);

        return User::query()->get()->sortBy('username')->values()->all();
    }

    /**
     * Create an account with a temporary password, returned for the admin
     * to hand out.
     *
     * @return array{user: User, temporaryPassword: string}
     *
     * @throws DomainException if the username is taken or the role is unknown.
     */
    public function createUser(User $admin, string $username, string $role): array
    {
        $this->requireAdmin($admin);

        if (!in_array($role, [User::ROLE_USER, User::ROLE_ADMIN], true)) {
            throw new DomainException('Unknown role.');
        }

        if (User::query()->where('username', $username)->exists()) {
            throw new DomainException("The username $username is taken.");
        }

        $temporaryPassword = $this->passwords->temporaryPassword();
        $user = new User(['username' => $username, 'role' => $role]);
        $this->passwords->setTemporaryPassword($user, $temporaryPassword);
        $user->save();

        return ['user' => $user, 'temporaryPassword' => $temporaryPassword];
    }

    /**
     * Give a user a new temporary password, remove their authenticator and
     * sign them out everywhere. Returns the temporary password.
     */
    public function resetPassword(User $admin, User $target): string
    {
        $this->requireAdminActingOnOther($admin, $target);

        $temporaryPassword = $this->passwords->temporaryPassword();
        $this->passwords->setTemporaryPassword($target, $temporaryPassword);
        $target->totp_secret = null;
        $target->totp_last_step = null;
        $target->session_version = $target->session_version + 1;
        $target->save();

        return $temporaryPassword;
    }

    /**
     * @throws DomainException if the target is already an admin.
     */
    public function promote(User $admin, User $target): void
    {
        $this->requireAdminActingOnOther($admin, $target);

        if ($target->isAdmin()) {
            throw new DomainException("{$target->username} is already an admin.");
        }

        $target->role = User::ROLE_ADMIN;
        $target->save();
    }

    /**
     * @throws DomainException if the target isn't an admin, or it would leave no admin.
     */
    public function demote(User $admin, User $target): void
    {
        $this->requireAdminActingOnOther($admin, $target);

        if (!$target->isAdmin()) {
            throw new DomainException("{$target->username} is not an admin.");
        }

        $this->requireAdminsLeftAfterRemoving();
        $target->role = User::ROLE_USER;
        $target->save();
    }

    /**
     * @throws DomainException if it would leave no admin.
     */
    public function delete(User $admin, User $target): void
    {
        $this->requireAdminActingOnOther($admin, $target);

        if ($target->isAdmin()) {
            $this->requireAdminsLeftAfterRemoving();
        }

        $target->delete();
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage users.');
        }
    }

    private function requireAdminActingOnOther(User $admin, User $target): void
    {
        $this->requireAdmin($admin);

        if ($admin->id === $target->id) {
            throw new DomainException("You can't change your own account here.");
        }
    }

    private function requireAdminsLeftAfterRemoving(): void
    {
        if (User::query()->where('role', User::ROLE_ADMIN)->count() - 1 < self::MINIMUM_ADMINS) {
            throw new DomainException('There must always be at least one admin.');
        }
    }
}
