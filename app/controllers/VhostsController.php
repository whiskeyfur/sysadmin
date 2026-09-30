<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\ApacheReportService;
use App\Services\HistoryReport;
use App\Services\VhostService;

/**
 * Apache virtual hosts, as found in each server's configuration (the Vhosts
 * menu): the list, and a report per vhost from the logs it writes to.
 */
class VhostsController extends Controller
{
    public function index()
    {
        $service = new VhostService();
        $vhosts = $service->all();

        $this->response->view('vhosts.index', [
            'auth' => $this->authContext(),
            'vhosts' => $vhosts,
            'certificates' => $service->certificates($vhosts),
            'error' => $this->request->flash('error'),
        ]);
    }

    /**
     * ?vhost=<id> (else the first)&range=24h|7d|30d
     */
    public function reports()
    {
        $vhosts = array_values(array_filter((new VhostService())->all(), fn ($v) => $v->server !== null));
        $vhost = collect($vhosts)->firstWhere('id', (int) $this->request->get('vhost')) ?? ($vhosts[0] ?? null);
        $range = (string) $this->request->get('range');
        $range = isset(HistoryReport::RANGES[$range]) ? $range : HistoryReport::DEFAULT_RANGE;
        $reports = new ApacheReportService();

        $this->response->view('reports.vhost', [
            'auth' => $this->authContext(),
            'vhosts' => $vhosts,
            'vhost' => $vhost,
            'range' => $range,
            'logs' => $vhost?->server === null ? null : $reports->vhostLogs($vhost->server, $vhost),
            'report' => $vhost?->server === null ? null : $reports->report($vhost->server, $range, $vhost),
        ]);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
