<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\DTOs\ServerTestResult;
use App\DTOs\SshSetupResult;
use App\Models\Server;
use App\Services\CaCertificateService;
use App\Services\HealthCheckService;
use App\Services\ServerService;
use App\Services\ServerTestService;
use App\Services\SshKeyService;
use App\Services\SshSetupService;
use DomainException;

/**
 * Server configurations: everyone sees the list, admins manage servers,
 * test connections and trust host keys.
 */
class ServerController extends Controller
{
    private const FIELDS = ['name', 'hostname', 'ssh_port', 'ssh_username', 'ssh_password_allowed', 'mysql_enabled', 'mysql_host', 'mysql_port', 'mysql_username', 'mysql_tls', 'mysql_tls_ca'];

    private readonly ServerService $servers;

    public function __construct()
    {
        parent::__construct();

        $this->servers = new ServerService();
    }

    public function index()
    {
        $auth = $this->authContext();

        $this->response->view('servers.index', [
            'auth' => $auth,
            'servers' => $this->servers->all(),
            'publicKey' => $auth->isAdmin() ? (new SshKeyService())->publicKey() : null,
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    public function show($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $health = new HealthCheckService();

        $this->response->view('servers.show', [
            'auth' => $this->authContext(),
            'server' => $server,
            'checks' => $health->latest($server),
            'history' => $health->history($server),
            'health' => $health,
            'canCheck' => $health->canCheck($server),
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    public function runChecks($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        try {
            (new HealthCheckService())->run($this->authContext()->user, $server);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/servers/{$server->id}");

            return;
        }

        $this->response->redirect("/servers/{$server->id}");
    }

    public function create()
    {
        $this->renderForm(new Server(['ssh_port' => 22, 'mysql_port' => 3306, 'mysql_tls' => Server::TLS_VERIFY]));
    }

    public function store()
    {
        try {
            $server = $this->servers->create($this->authContext()->user, $this->input());
        } catch (DomainException $e) {
            $this->renderForm(new Server($this->input(withPassword: false)), $e->getMessage());

            return;
        }

        $this->response->redirect("/admin/servers/{$server->id}/ssh-setup");
    }

    public function edit($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->renderForm($server);
        }
    }

    public function update($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        try {
            $this->servers->update($this->authContext()->user, $server, $this->input());
        } catch (DomainException $e) {
            $this->renderForm($server->fill($this->input(withPassword: false)), $e->getMessage());

            return;
        }

        $this->response->withFlash('notice', "Saved {$server->name}.")->redirect('/servers');
    }

    public function delete($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->servers->delete($this->authContext()->user, $server);
            $this->response->withFlash('notice', "Deleted {$server->name}.")->redirect('/servers');
        }
    }

    public function test($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->renderTest($server, (new ServerTestService())->test($this->authContext()->user, $server));
        }
    }

    public function sshSetup($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->renderSetup($server);
        }
    }

    public function runSshSetup($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $fingerprint = $this->request->get('fingerprint', false);
        $password = $server->ssh_password_allowed ? (string) $this->request->get('password', false) : null;

        $result = (new SshSetupService())->setUp($this->authContext()->user, $server, is_string($fingerprint) ? $fingerprint : null, $password);
        $this->renderSetup($server->fresh() ?? $server, $result);
    }

    private function renderForm(Server $server, ?string $error = null): void
    {
        $this->response->view('servers.form', [
            'auth' => $this->authContext(),
            'server' => $server,
            'caCertificates' => $server->mysql_tls_ca ? (new CaCertificateService())->describe($server->mysql_tls_ca) : [],
            'error' => $error,
        ], $error === null ? 200 : 422);
    }

    private function renderSetup(Server $server, ?SshSetupResult $result = null): void
    {
        // Only fetch the host key when it still has to be trusted.
        [$hostKey, $hostKeyError] = $server->ssh_host_key === null ? (new SshSetupService())->presentedHostKey($server) : [null, null];

        $this->response->view('servers.setup', [
            'auth' => $this->authContext(),
            'server' => $server,
            'hostKey' => $hostKey,
            'hostKeyError' => $hostKeyError,
            'publicKey' => (new SshKeyService())->publicKey(),
            'result' => $result,
        ]);
    }

    private function renderTest(Server $server, ServerTestResult $result): void
    {
        $this->response->view('servers.test', [
            'auth' => $this->authContext(),
            'server' => $server,
            'result' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(bool $withPassword = true): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $input[$field] = $this->request->get($field, false);
        }

        if ($withPassword) {
            $input['mysql_password'] = (string) $this->request->get('mysql_password', false);
        }

        return $input;
    }

    private function findOrRedirect($id): ?Server
    {
        $server = Server::query()->find((int) $id);

        if (!$server instanceof Server) {
            $this->response->withFlash('error', 'That server no longer exists.')->redirect('/servers');

            return null;
        }

        return $server;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
