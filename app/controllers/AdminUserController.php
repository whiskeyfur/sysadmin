<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\User;
use App\Services\UserAdminService;
use App\Services\UserKeyService;
use DomainException;

/**
 * Admins approve or reject pending registrations here.
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

    /**
     * @param callable(AuthContext, User): string $action
     */
    private function act($id, callable $action): void
    {
        $target = User::query()->find((int) $id);

        if (!$target instanceof User) {
            $this->response->withFlash('error', 'That user no longer exists.')->redirect('/admin/users');

            return;
        }

        try {
            $message = $action($this->authContext(), $target);
            $this->response->withFlash('notice', $message)->redirect('/admin/users');
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/users');
        }
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
