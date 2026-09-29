<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Middleware\LimitConcurrentLogins;
use App\Models\User;
use App\Services\PasswordService;
use App\Services\UserAdminService;
use DomainException;

/**
 * Admin user management: create accounts (with a one-time password),
 * change roles, reset sign-in and delete users.
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
            'minLength' => PasswordService::MIN_LENGTH,
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
            $created = $this->admins->createUser($auth->user, $username, $role, (string) $this->request->get('one_time_password', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        $this->response->withFlash('notice', "Created {$created->username}. Give them their username and the one-time password outside this website; at first sign-in they set up an authenticator app.")
            ->redirect('/admin/users');
    }

    public function resetPassword($id)
    {
        $auth = $this->authContext();
        $target = $this->findOrRedirect($id);

        if ($target === null) {
            return;
        }

        try {
            $this->admins->resetPassword($auth->user, $target, (string) $this->request->get('one_time_password', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        $this->response->withFlash('notice', "Reset {$target->username}: they've been signed out and their authenticator removed. Give them the one-time password outside this website to set up a new one.")
            ->redirect('/admin/users');
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
