<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\UserAdminService;

class DashboardController extends Controller
{
    public function index()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');
        $admins = new UserAdminService();

        $this->response->view('dashboard', [
            'auth' => $auth,
            'needsSecondAdmin' => $admins->needsSecondAdmin((string) $auth->masterKey),
            'minimumAdmins' => UserAdminService::MINIMUM_ADMINS,
        ]);
    }
}
