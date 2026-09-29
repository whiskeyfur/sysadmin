<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Middleware\LimitConcurrentLogins;
use App\Models\User;
use App\Services\PasswordService;
use App\Services\UserAdminService;
use DomainException;

/**
 * Admin user management: create accounts, change roles, reset passwords
 * and delete users.
 */
class AdminUserController extends Controller
{
    private readonly UserAdminService $admins;

    public function __construct()
    {
        parent::__construct();

        $this->admins = new UserAdminService();
    }

    public function index()
    {
        $auth = $this->authContext();

        $this->response->view('admin.users', [
            'auth' => $auth,
            'users' => $this->admins->listUsers($auth->user),
            'passwords' => new PasswordService(),
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    public function create()
    {
        $auth = $this->authContext();
        $username = (string) $this->request->get('username', false);
        $role = $this->request->get('role') === User::ROLE_ADMIN ? User::ROLE_ADMIN : User::ROLE_USER;

        if ($this->request->validate(['username' => 'username|between:[3,32]']) === false) {
            $this->response->withFlash('error', 'Usernames are 3–32 letters, numbers or underscores.')->redirect('/admin/users');

            return;
        }

        try {
            $created = $this->admins->createUser($auth->user, $username, $role);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        $this->showTemporaryPassword(
            $auth,
            "Created {$created['user']->username}",
            "Give {$created['user']->username} their username and this temporary password outside this website. At first sign-in they must choose their own password and set up an authenticator app.",
            $created['temporaryPassword'],
        );
    }

    public function resetPassword($id)
    {
        $auth = $this->authContext();
        $target = $this->findOrRedirect($id);

        if ($target === null) {
            return;
        }

        try {
            $temporaryPassword = $this->admins->resetPassword($auth->user, $target);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        $this->showTemporaryPassword(
            $auth,
            "Password reset for {$target->username}",
            "Give {$target->username} this temporary password outside this website. They've been signed out, and at their next sign-in they must choose a new password and set up their authenticator again.",
            $temporaryPassword,
        );
    }

    public function promote($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->promote($auth->user, $target);

            return "{$target->username} is now an admin.";
        });
    }

    public function demote($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->demote($auth->user, $target);

            return "{$target->username} is no longer an admin.";
        });
    }

    public function delete($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->delete($auth->user, $target);

            return "Deleted {$target->username}.";
        });
    }

    private function showTemporaryPassword(AuthContext $auth, string $title, string $message, string $temporaryPassword): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('admin.done', [
            'auth' => $auth,
            'title' => $title,
            'message' => $message,
            'temporaryPassword' => $temporaryPassword,
        ]);
    }

    /**
     * @param callable(AuthContext, User): string $action
     */
    private function act($id, callable $action): void
    {
        $target = $this->findOrRedirect($id);

        if ($target === null) {
            return;
        }

        try {
            $message = $action($this->authContext(), $target);
            $this->response->withFlash('notice', $message)->redirect('/admin/users');
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');
        }
    }

    private function findOrRedirect($id): ?User
    {
        $target = User::query()->find((int) $id);

        if (!$target instanceof User) {
            $this->response->withFlash('error', 'That user no longer exists.')->redirect('/admin/users');

            return null;
        }

        return $target;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
