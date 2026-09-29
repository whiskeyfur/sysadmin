<?php

namespace App\Controllers;

use App\DTOs\AuthContext;

/**
 * Reports for each monitoring area (the first item of its menu). Placeholders for now.
 */
class ReportController extends Controller
{
    private const AREAS = [
        'ssl' => ['title' => 'SSL reports', 'test' => '/ssl'],
        'ssh' => ['title' => 'SSH reports', 'test' => '/ssh'],
        'mariadb' => ['title' => 'MariaDB reports', 'test' => '/mariadb'],
    ];

    public function ssl()
    {
        $this->renderArea('ssl');
    }

    public function ssh()
    {
        $this->renderArea('ssh');
    }

    public function mariadb()
    {
        $this->renderArea('mariadb');
    }

    private function renderArea(string $area): void
    {
        $this->response->view('reports.placeholder', [
            'auth' => $this->authContext(),
            'title' => self::AREAS[$area]['title'],
            'test' => self::AREAS[$area]['test'],
        ]);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
