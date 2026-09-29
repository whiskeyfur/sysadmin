<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Services\ServerService;
use App\Services\SshReportService;

/**
 * Reports for each monitoring area (the first item of its menu). SSH
 * reports chart disk, load and memory over time; the others are
 * placeholders for now.
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

    /**
     * ?server=<id>&range=24h|7d|30d
     */
    public function ssh()
    {
        $servers = array_values(array_filter((new ServerService())->all(), fn (Server $s) => $s->ssh_enabled));
        $id = (int) $this->request->get('server');
        $server = collect($servers)->firstWhere('id', $id) ?? ($servers[0] ?? null);
        $range = (string) $this->request->get('range');
        $range = isset(SshReportService::RANGES[$range]) ? $range : SshReportService::DEFAULT_RANGE;

        $this->response->view('reports.ssh', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new SshReportService())->report($server, $range),
        ]);
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
