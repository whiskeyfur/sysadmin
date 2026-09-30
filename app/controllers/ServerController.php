<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Enums\HealthStatus;
use App\Models\Server;
use App\Models\SslBinding;
use App\Exceptions\ServerConnectionException;
use App\Services\HealthCheckService;
use App\Services\MariadbLogService;
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
     * Apache monitoring: from each server's Apache configuration and logs, over SSH.
     */
    public function apache()
    {
        $this->renderKind(HealthCheckService::KIND_APACHE);
    }

    /**
     * Scan Apache's configuration again at the next check (admins), e.g.
     * after adding a virtual host or moving a log; and check now.
     */
    public function rescanApache($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $server->apache_scanned_at = null;
        $server->save();

        try {
            (new HealthCheckService())->run($this->authContext()->user, $server);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/apache');

            return;
        }

        $this->response->withFlash('notice', "Scanned Apache's configuration on {$server->name} again and checked it.")->redirect('/apache');
    }

    /**
     * MariaDB monitoring: every database check for every server with MariaDB/MySQL.
     */
    public function mariadb()
    {
        $this->renderKind(HealthCheckService::KIND_MYSQL);
    }

    /**
     * /servers: every server, with a button per report (SSH, MariaDB, Apache, its virtual hosts) coloured
     * by its current worst status: the latest checks of that kind; for virtual hosts, the monitored
     * certificates that cover their names.
     */
    public function servers()
    {
        $health = new HealthCheckService();
        $vhostService = new \App\Services\VhostService();
        $allVhosts = $vhostService->all();
        $certificates = $vhostService->certificates($allVhosts);
        $servers = (new ServerService())->all();
        $rows = [];

        foreach ($servers as $server) {
            $vhosts = array_values(array_filter($allVhosts, fn ($v) => $v->server_id === $server->id));
            $covering = array_values(array_filter(array_map(fn ($v) => $certificates[$v->id] ?? null, $vhosts)));
            $statuses = array_values(array_filter(array_map(fn ($c) => HealthStatus::tryFrom((string) $c->last_status), $covering)));

            $rows[] = [
                'server' => $server,
                'summary' => $health->summary($server),
                'vhosts' => count($vhosts),
                'vhost_status' => $statuses === [] ? null : HealthStatus::worst($statuses),
                'vhost_note' => count($vhosts) === 0 ? '' : count($covering) . ' of ' . count($vhosts) . ' covered by a monitored certificate'
                    . ($statuses === [] ? '' : '; worst: ' . HealthStatus::worst($statuses)->value),
            ];
        }

        $this->response->view('servers.list', [
            'auth' => $this->request->next('auth'),
            'rows' => $rows,
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
    /**
     * Import the server's MariaDB log over SSH (admins), then show the
     * MariaDB report with what was found where.
     */
    public function importLog($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $report = "/mariadb/reports?server={$server->id}";

        try {
            $result = (new MariadbLogService())->import($this->authContext()->user, $server);
        } catch (DomainException | ServerConnectionException $e) {
            $this->response->withFlash('error', "Couldn't import the log of {$server->name}: " . $e->getMessage())->redirect($report);

            return;
        }

        $imported = array_sum(array_column($result['sources'], 'imported'));
        $this->response
            ->withFlash('notice', "Imported $imported new log entr" . ($imported === 1 ? 'y' : 'ies') . " from {$server->name}.")
            ->withFlash('log_import', (string) json_encode($result))
            ->redirect($report);
    }

    private function back(string $default): string
    {
        $back = $this->request->get('back', false);

        return in_array($back, ['/', '/ssh', '/mariadb', '/apache'], true) ? $back : $default;
    }

    private function renderKind(string $kind): void
    {
        $health = new HealthCheckService();
        $servers = array_values(array_filter(
            (new ServerService())->all(),
            fn (Server $s) => match ($kind) {
                HealthCheckService::KIND_SSH => $s->ssh_enabled,
                HealthCheckService::KIND_APACHE => $s->apache_enabled,
                default => $s->mysql_enabled,
            },
        ));
        $results = [];

        foreach ($servers as $server) {
            $results[$server->id] = collect($health->latestByKind($server)[$kind])->keyBy('check_key')->all();
        }

        $this->response->view('servers.kind', [
            'auth' => $this->authContext(),
            'kind' => $kind,
            'title' => [HealthCheckService::KIND_SSH => 'SSH', HealthCheckService::KIND_APACHE => 'Apache'][$kind] ?? 'MariaDB',
            // The page's path, for links back to it.
            'page' => [HealthCheckService::KIND_SSH => '/ssh', HealthCheckService::KIND_APACHE => '/apache'][$kind] ?? '/mariadb',
            'columns' => $health->columns($kind),
            'connectionKey' => [HealthCheckService::KIND_SSH => 'ssh', HealthCheckService::KIND_APACHE => 'apache'][$kind] ?? 'connection',
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
