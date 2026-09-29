<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Services\HistoryReport;
use App\Services\MariadbReportService;
use App\Services\ServerService;
use App\Services\SshReportService;

/**
 * Reports for each monitoring area (the first item of its menu). SSH and
 * MariaDB reports chart their check values over time; SSL is a placeholder
 * for now.
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
        [$servers, $server, $range] = $this->pick(fn (Server $s) => $s->ssh_enabled);

        $this->response->view('reports.ssh', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new SshReportService())->report($server, $range),
        ]);
    }

    /**
     * ?server=<id>&range=24h|7d|30d
     */
    public function mariadb()
    {
        [$servers, $server, $range] = $this->pick(fn (Server $s) => $s->mysql_enabled);

        $this->response->view('reports.mariadb', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new MariadbReportService())->report($server, $range),
        ]);
    }

    /**
     * The servers a report covers, the one picked (?server, else the first)
     * and the period (?range, else the default).
     *
     * @param callable(Server): bool $covered
     * @return array{0: list<Server>, 1: Server|null, 2: string}
     */
    private function pick(callable $covered): array
    {
        $servers = array_values(array_filter((new ServerService())->all(), $covered));
        $server = collect($servers)->firstWhere('id', (int) $this->request->get('server')) ?? ($servers[0] ?? null);
        $range = (string) $this->request->get('range');

        return [$servers, $server, isset(HistoryReport::RANGES[$range]) ? $range : HistoryReport::DEFAULT_RANGE];
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
