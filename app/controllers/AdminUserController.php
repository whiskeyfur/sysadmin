<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Middleware\LimitConcurrentLogins;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\UserAdminService;
use App\Services\UserKeyService;
use DomainException;

/**
 * Admin user management: approvals, admin role changes, password resets,
 * deleting users and replacing the master key.
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
            'users' => $this->admins->listUsers($auth->user, (string) $auth->masterKey),
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    public function approve($id)
    {
        $role = $this->request->get('role') === UserKeyService::ROLE_ADMIN ? UserKeyService::ROLE_ADMIN : UserKeyService::ROLE_USER;

        $this->act($id, function (AuthContext $auth, User $target) use ($role) {
            $this->admins->approve($auth->user, (string) $auth->masterKey, $target, $role);

            return "Approved {$target->username} as " . ($role === UserKeyService::ROLE_ADMIN ? 'an admin' : 'a user') . '.';
        });
    }

    public function reject($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->reject($auth->user, (string) $auth->masterKey, $target);

            return "Rejected {$target->username}.";
        });
    }

    public function promote($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->promote($auth->user, (string) $auth->masterKey, $target);

            return "{$target->username} is now an admin.";
        });
    }

    public function demote($id)
    {
        $this->act($id, function (AuthContext $auth, User $target) {
            $this->admins->demote($auth->user, (string) $auth->masterKey, $target);

            return "{$target->username} is no longer an admin.";
        });
    }

    public function resetPassword($id)
    {
        $auth = $this->authContext();
        $target = $this->findOrRedirect($id);

        if ($target === null) {
            return;
        }

        try {
            $temporaryPassword = $this->admins->resetPassword($auth->user, (string) $auth->masterKey, $target);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('admin.done', [
            'auth' => $auth,
            'title' => "Password reset for {$target->username}",
            'message' => "Give {$target->username} this temporary password outside this website. It's shown only once. They've been signed out, and at their next sign-in they must choose a new password and set up their authenticator again.",
            'temporaryPassword' => $temporaryPassword,
            'offerKeyFile' => false,
        ]);
    }

    public function confirmDelete($id)
    {
        $target = $this->findOrRedirect($id);

        if ($target !== null) {
            $this->renderConfirm('delete', $target);
        }
    }

    public function delete($id)
    {
        $auth = $this->authContext();
        $target = $this->findOrRedirect($id);

        if ($target === null) {
            return;
        }

        $username = $target->username;

        $this->withPassword('delete', $target, fn (string $password) => $this->admins->deleteUser($auth->user, (string) $auth->masterKey, $target, $password, $this->clientIp()), function () use ($auth, $username) {
            $this->response->view('admin.done', [
                'auth' => $auth,
                'title' => "Deleted $username",
                'message' => "$username has been deleted and the master key has been replaced, because they had seen the old one. Download the new key file and send it to every other user outside this website; each of them must upload it at their next sign-in.",
                'temporaryPassword' => null,
                'offerKeyFile' => true,
            ]);
        });
    }

    public function confirmRotate()
    {
        $this->renderConfirm('rotate');
    }

    public function rotate()
    {
        $auth = $this->authContext();

        $this->withPassword('rotate', null, fn (string $password) => $this->admins->rotateMasterKey($auth->user, $password, $this->clientIp()), function () use ($auth) {
            $this->response->view('admin.done', [
                'auth' => $auth,
                'title' => 'Master key replaced',
                'message' => 'Download the new key file and send it to every other user outside this website; each of them must upload it at their next sign-in. The old key file no longer works.',
                'temporaryPassword' => null,
                'offerKeyFile' => true,
            ]);
        });
    }

    /**
     * Run an action that needs the admin's password and returns a new master
     * key, re-seal the admin's session with that key, then call $done.
     *
     * @param string $action "delete" or "rotate", for re-rendering the confirmation page.
     * @param callable(string): string $rotate Takes the password, returns the new master key.
     * @param callable(): void $done
     */
    private function withPassword(string $action, ?User $target, callable $rotate, callable $done): void
    {
        $password = (string) $this->request->get('password', false);

        try {
            $newMasterKey = $rotate($password);
        } catch (InvalidCredentialsException) {
            $this->renderConfirm($action, $target, 'Wrong password.');

            return;
        } catch (TooManyAttemptsException $e) {
            $this->response->withHeader('Retry-After', (string) $e->retryAfter);
            $this->renderConfirm($action, $target, 'Too many attempts. Try again in ' . $this->retryMinutes($e->retryAfter) . ' minute(s).', 429);

            return;
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');

            return;
        } finally {
            LimitConcurrentLogins::release();
        }

        (new AuthSessionService())->start($this->authContext()->user, $newMasterKey);
        $done();
    }

    private function renderConfirm(string $action, ?User $target = null, ?string $error = null, int $status = 200): void
    {
        $this->response->view('admin.confirm', [
            'auth' => $this->authContext(),
            'action' => $action,
            'target' => $target,
            'error' => $error,
        ], $status);
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
