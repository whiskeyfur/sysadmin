<?php

namespace App\Controllers;

use App\DTOs\AuthContext;

class DashboardController extends Controller
{
    public function index()
    {
        /** @var AuthContext $auth */
        $auth = $this->request->next('auth');

        $this->response->view('dashboard', [
            'auth' => $auth,
            'notice' => $this->request->flash('notice'),
        ]);
    }
}
