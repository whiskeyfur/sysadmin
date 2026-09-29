<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\PasswordService;

class DashboardController extends Controller
{
    public function index()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');
        $changedAt = $auth->user->password_changed_at;

        $this->response->view('dashboard', [
            'auth' => $auth,
            'notice' => $this->request->flash('notice'),
            'passwordExpiresAt' => $changedAt?->copy()->addDays(PasswordService::MAX_AGE_DAYS),
        ]);
    }
}
