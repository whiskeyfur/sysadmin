<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Models\SslBinding;
use App\Services\HealthCheckService;
use App\Services\ServerService;
use App\Services\SshKeyService;
use App\Services\SslMonitorService;
use DomainException;

/**
 * Server monitoring: the overview of every server (the home page), the SSH
 * and MariaDB pages with each check per server, and one server's results.
 * Configuration is in ServerConfigController.
 */
class ServerController extends Controller
{
    public function index()
    {
        (new SslMonitorService())->convertLegacy();
        $health = new HealthCheckService();
        $servers = (new ServerService())->all();
        $summaries = [];

        foreach ($servers as $server) {
            $summaries[$server->id] = $health->summary($server);
        }

        $this->response->view('servers.index', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'summaries' => $summaries,
            'sslCounts' => SslBinding::query()->whereNotNull('server_id')->get()->countBy('server_id')->all(),
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    /**
     * SSH monitoring: disk, load and memory for every server with SSH.
     */
    public function ssh()
    {
        $this->renderKind(HealthCheckService::KIND_SSH);
    }

    /**
     * MariaDB monitoring: every database check for every server with MariaDB/MySQL.
     */
    public function mariadb()
    {
        $this->renderKind(HealthCheckService::KIND_MYSQL);
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
            'checks' => $health->latestByKind($server),
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
            $this->response->withFlash('error', $e->getMessage())->redirect($this->back("/servers/{$server->id}"));

            return;
        }

        $this->response->withFlash('notice', "Checked {$server->name}.")->redirect($this->back("/servers/{$server->id}"));
    }

    /**
     * The monitoring page a form was sent from, else $default.
     */
    private function back(string $default): string
    {
        $back = $this->request->get('back', false);

        return in_array($back, ['/', '/ssh', '/mariadb'], true) ? $back : $default;
    }

    private function renderKind(string $kind): void
    {
        $health = new HealthCheckService();
        $servers = array_values(array_filter(
            (new ServerService())->all(),
            fn (Server $s) => $kind === HealthCheckService::KIND_SSH ? $s->ssh_enabled : $s->mysql_enabled,
        ));
        $results = [];

        foreach ($servers as $server) {
            $results[$server->id] = collect($health->latestByKind($server)[$kind])->keyBy('check_key')->all();
        }

        $this->response->view('servers.kind', [
            'auth' => $this->authContext(),
            'kind' => $kind,
            'title' => $kind === HealthCheckService::KIND_SSH ? 'SSH' : 'MariaDB',
            'columns' => $health->columns($kind),
            'connectionKey' => $kind === HealthCheckService::KIND_SSH ? 'ssh' : 'connection',
            'servers' => $servers,
            'results' => $results,
            'health' => $health,
            'publicKey' => $kind === HealthCheckService::KIND_SSH && $this->authContext()->isAdmin() ? (new SshKeyService())->publicKey() : null,
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    private function findOrRedirect($id): ?Server
    {
        $server = Server::query()->find((int) $id);

        if (!$server instanceof Server) {
            $this->response->redirect('/');

            return null;
        }

        return $server;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
