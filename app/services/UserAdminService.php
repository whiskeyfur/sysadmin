<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Models\User;
use DomainException;

/**
 * Admin user management. Accounts are created by admins only, with a
 * one-time password the admin sets and hands out; the user uses it once to
 * enrol their authenticator, then signs in with username and code.
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
     * Create an account with the one-time password the admin chose.
     *
     * @throws DomainException if the username is taken, the role is unknown or the password too weak.
     */
    public function createUser(User $admin, string $username, string $role, string $oneTimePassword): User
    {
        $this->requireAdmin($admin);

        if (!in_array($role, [User::ROLE_USER, User::ROLE_ADMIN], true)) {
            throw new DomainException('Unknown role.');
        }

        if (User::query()->where('username', $username)->exists()) {
            throw new DomainException("The username $username is taken.");
        }

        $this->requireAcceptable($oneTimePassword);
        $user = new User(['username' => $username, 'role' => $role]);
        $this->passwords->setOneTimePassword($user, $oneTimePassword);
        $user->save();

        return $user;
    }

    /**
     * Server-side recovery (`php leaf app:reset-admin`): reset a user to a
     * given one-time password, remove their authenticator, sign them out
     * and clear their login lockout. A missing "admin" user is recreated as
     * an admin, so there's always a way back in.
     *
     * @return array{user: User, created: bool}
     *
     * @throws DomainException if the user doesn't exist (and isn't "admin")
     */
    public function resetFromConsole(string $username, string $oneTimePassword, LoginThrottleService $throttle = new LoginThrottleService()): array
    {
        $user = User::query()->where('username', $username)->first();
        $created = false;

        if ($user === null) {
            if ($username !== AuthService::DEFAULT_ADMIN_USERNAME) {
                throw new DomainException("There is no user called $username.");
            }

            $user = new User(['username' => $username, 'role' => User::ROLE_ADMIN]);
            $created = true;
        }

        $this->passwords->setOneTimePassword($user, $oneTimePassword);
        $user->save(); // an id, for its passkeys
        $this->forgetSignInMethods($user);
        $user->session_version = (int) $user->session_version + 1;
        $user->save();
        $throttle->recordLoginSuccess($username);

        return ['user' => $user, 'created' => $created];
    }

    /**
     * Remove all of a user's ways to sign in (password, authenticator,
     * passkeys), give them the one-time password the admin chose and sign
     * them out everywhere, so they set up again (e.g. after losing their
     * phone).
     *
     * @throws DomainException if the password is too weak.
     */
    public function resetPassword(User $admin, User $target, string $oneTimePassword): void
    {
        $this->requireAdminActingOnOther($admin, $target);
        $this->requireAcceptable($oneTimePassword);

        $this->passwords->setOneTimePassword($target, $oneTimePassword);
        $this->forgetSignInMethods($target);
        $target->session_version = $target->session_version + 1;
        $target->save();
    }

    private function forgetSignInMethods(User $user): void
    {
        $user->login_password = null;
        $user->password_changed_at = null;
        $user->totp_secret = null;
        $user->totp_last_step = null;
        \App\Models\Passkey::query()->where('user_id', $user->id)->delete();
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

        \App\Models\Passkey::query()->where('user_id', $target->id)->delete();
        $target->delete();
    }

    private function requireAcceptable(string $oneTimePassword): void
    {
        $error = $this->passwords->policyError($oneTimePassword);

        if ($error !== null) {
            throw new DomainException($error);
        }
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
