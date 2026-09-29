<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Models\SslBinding;
use App\Services\HealthCheckService;
use App\Services\ServerService;
use App\Services\SslMonitorService;
use DomainException;

/**
 * Server monitoring: the health and SSL status of every server, and one
 * server's results. Configuration is in ServerConfigController.
 */
class ServerController extends Controller
{
    public function index()
    {
        (new SslMonitorService())->convertLegacy();

        $this->response->view('servers.index', [
            'auth' => $this->authContext(),
            'servers' => (new ServerService())->all(),
            'sslCounts' => SslBinding::query()->whereNotNull('server_id')->get()->countBy('server_id')->all(),
        ]);
    }

    public function show($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $health = new HealthCheckService();
        $ssl = new SslMonitorService();

        $this->response->view('servers.show', [
            'auth' => $this->authContext(),
            'server' => $server,
            'checks' => $health->latest($server),
            'history' => $health->history($server),
            'health' => $health,
            'canCheck' => $health->canCheck($server) || $ssl->bindingsFor($server) !== [],
            'bindings' => $ssl->bindingsFor($server),
            'ssl' => $ssl,
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    /**
     * Run everything configured for the server: health checks and SSL.
     */
    public function runChecks($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $user = $this->authContext()->user;
        $health = new HealthCheckService();

        try {
            if ($health->canCheck($server)) {
                $health->run($user, $server);
            }

            (new SslMonitorService())->checkServer($user, $server);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/servers/{$server->id}");

            return;
        }

        $this->response->redirect("/servers/{$server->id}");
    }

    private function findOrRedirect($id): ?Server
    {
        $server = Server::query()->find((int) $id);

        if (!$server instanceof Server) {
            $this->response->redirect('/servers');

            return null;
        }

        return $server;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
