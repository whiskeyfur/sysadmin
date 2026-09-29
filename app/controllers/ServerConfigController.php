<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\DTOs\ServerTestResult;
use App\DTOs\SshSetupResult;
use App\Models\Server;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\CaCertificateService;
use App\Services\ServerService;
use App\Services\ServerTestService;
use App\Services\SshKeyService;
use App\Services\SshSetupService;
use App\Services\SslMonitorService;
use DomainException;

/**
 * Server configuration (admins): each server has one or more of SSH,
 * MariaDB/MySQL and SSL monitoring. Also connection tests and SSH setup.
 * Monitoring views are in ServerController and SslController.
 */
class ServerConfigController extends Controller
{
    private const BACK_PAGES = ['/', '/ssh', '/mariadb', '/apache'];

    private const FIELDS = ['name', 'hostname', 'ssh_enabled', 'ssh_port', 'ssh_account_id', 'ssh_username', 'ssh_password_allowed', 'mysql_enabled', 'mysql_host', 'mysql_port', 'mysql_account_id', 'mysql_username', 'mysql_tls', 'mysql_tls_ca', 'apache_enabled', 'apache_config_file', 'apache_error_logs', 'apache_access_logs'];

    private readonly ServerService $servers;

    public function __construct()
    {
        parent::__construct();

        $this->servers = new ServerService();
    }

    /**
     * The old Configure page: its tools now live on the monitoring pages.
     */
    public function index()
    {
        $this->response->redirect('/');
    }

    /**
     * ?kind=ssh or ?kind=mysql shows that module's form only: for a new
     * server, or to add the module to an existing one. Without it, the full
     * form (every module).
     */
    public function create()
    {
        $this->renderCreate($this->kind());
    }

    /**
     * SSH › Add.
     */
    public function createSsh()
    {
        $this->renderCreate('ssh', '/ssh');
    }

    /**
     * MariaDB › Add.
     */
    public function createApache()
    {
        $this->renderCreate('apache', '/apache');
    }

    public function createMariadb()
    {
        $this->renderCreate('mysql', '/mariadb');
    }

    /**
     * @param 'ssh'|'mysql'|'apache'|null $kind
     */
    private function renderCreate(?string $kind, ?string $back = null): void
    {
        $this->renderForm(new Server([
            'ssh_enabled' => $kind !== 'mysql',
            'mysql_enabled' => $kind === 'mysql',
            'apache_enabled' => $kind === 'apache',
            'ssh_port' => 22,
            'mysql_port' => 3306,
            'mysql_tls' => Server::TLS_VERIFY,
        ]), kind: $kind, back: $back);
    }

    public function store()
    {
        $kind = $this->kind();
        $existing = $kind === null ? null : $this->candidate($kind);

        try {
            $server = $kind === null
                ? $this->servers->create($this->authContext()->user, $this->input())
                : $this->servers->saveModule($this->authContext()->user, $existing, $kind, $this->input());
        } catch (DomainException $e) {
            $this->renderForm($existing ?? new Server($this->input(withPassword: false)), $e->getMessage(), $kind);

            return;
        }

        if ($kind !== 'mysql' && $server->ssh_enabled && !$server->sshReady()) {
            $this->response->redirect("/admin/servers/{$server->id}/ssh-setup");

            return;
        }

        $this->response->withFlash('notice', "Added {$server->name}.")->redirect($this->back());
    }

    public function edit($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->renderForm($server, kind: $this->kind());
        }
    }

    public function update($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        $kind = $this->kind();

        try {
            if ($kind === null) {
                $this->servers->update($this->authContext()->user, $server, $this->input());
            } else {
                $this->servers->saveModule($this->authContext()->user, $server, $kind, $this->input());
            }
        } catch (DomainException $e) {
            $this->renderForm($server->fresh() ?? $server, $e->getMessage(), $kind);

            return;
        }

        $this->response->withFlash('notice', "Saved {$server->name}.")->redirect($this->back());
    }

    public function delete($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server !== null) {
            $this->servers->delete($this->authContext()->user, $server);
            $this->response->withFlash('notice', "Deleted {$server->name}.")->redirect($this->back());
        }
    }

    /**
     * Stop monitoring one module on a server (from that module's page); the
     * server is deleted once nothing is left on it.
     */
    public function remove($id)
    {
        $server = $this->findOrRedirect($id);
        $kind = $this->kind();

        if ($server === null || $kind === null) {
            return;
        }

        $module = ['ssh' => 'SSH', 'mysql' => 'MariaDB', 'apache' => 'Apache'][$kind];

        try {
            $deleted = $this->servers->removeModule($this->authContext()->user, $server, $kind);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect($this->back());

            return;
        }

        $this->response->withFlash('notice', $deleted
            ? "Removed {$server->name}: it had nothing else to monitor, so it was deleted."
            : "Stopped monitoring {$module} on {$server->name}. Its other monitoring is unchanged.")->redirect($this->back());
    }

    public function test($id)
    {
        $server = $this->findOrRedirect($id);

        if ($server === null) {
            return;
        }

        try {
            $result = (new ServerTestService())->test($this->authContext()->user, $server);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/servers/{$server->id}");

            return;
        }

        $this->renderTest($server, $result);
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

        // The fingerprint shown on the page counts only with a choice of how it was checked.
        $check = $this->request->get('host_key_check', false);
        $fingerprint = in_array($check, ['manual', 'login'], true) ? $this->request->get('fingerprint', false) : null;
        $password = $server->ssh_password_allowed ? (string) $this->request->get('password', false) : null;

        $result = (new SshSetupService())->setUp($this->authContext()->user, $server, is_string($fingerprint) ? $fingerprint : null, $password, $check === 'login');
        $this->renderSetup($server->fresh() ?? $server, $result);
    }

    /**
     * @param 'ssh'|'mysql'|'apache'|null $kind one module's form, or null for the full form
     */
    private function renderForm(Server $server, ?string $error = null, ?string $kind = null, ?string $back = null): void
    {
        $this->response->view('servers.form', [
            'auth' => $this->authContext(),
            'server' => $server,
            'kind' => $kind,
            // Adding a module: servers that don't have it yet can get it.
            'candidates' => $kind !== null && !$server->exists
                ? array_values(array_filter($this->servers->all(), fn (Server $s) => $this->canGain($s, $kind)))
                : [],
            'back' => $back ?? $this->back(),
            'caCertificates' => $server->mysql_tls_ca ? (new CaCertificateService())->describe($server->mysql_tls_ca) : [],
            'bindings' => $server->exists ? (new SslMonitorService())->bindingsFor($server) : [],
            'sharedAccounts' => $this->sharedAccounts($server, Account::SERVICE_SSH),
            'mysqlAccounts' => $this->sharedAccounts($server, Account::SERVICE_MYSQL),
            'mysqlPasswordStored' => $server->exists && $server->mysql_enabled && (new ServerService())->hasMysqlPassword($server),
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
            'storedPassword' => $server->ssh_password_allowed && (new ServerService())->hasSshPassword($server),
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
            $this->response->withFlash('error', 'That server no longer exists.')->redirect('/');

            return null;
        }

        return $server;
    }

    /**
     * The LDAP and shared accounts the server's SSH or database login can pick.
     *
     * @return list<Account>
     */
    private function sharedAccounts(Server $server, string $service): array
    {
        return array_values(array_filter(
            (new AccountService())->selectableFor($server->exists ? $server : null, $service),
            fn (Account $a) => $a->type !== Account::TYPE_LOCAL,
        ));
    }

    /**
     * The module a form or link is about: 'ssh', 'mysql', or null for all.
     *
     * @return 'ssh'|'mysql'|'apache'|null
     */
    private function kind(): ?string
    {
        $kind = $this->request->get('kind', false);

        return in_array($kind, ['ssh', 'mysql', 'apache'], true) ? $kind : null;
    }

    /**
     * The existing server chosen to get a module, if any; it must not have it yet.
     *
     * @param 'ssh'|'mysql'|'apache' $kind
     */
    private function candidate(string $kind): ?Server
    {
        $id = filter_var($this->request->get('existing_id', false), FILTER_VALIDATE_INT);
        $server = $id === false ? null : Server::query()->find($id);

        return $server instanceof Server && $this->canGain($server, $kind) ? $server : null;
    }

    /**
     * Whether a server can have a module added: it doesn't have it yet (and,
     * for Apache, which is read over SSH, it has SSH).
     */
    private function canGain(Server $server, string $kind): bool
    {
        return match ($kind) {
            'ssh' => !$server->ssh_enabled,
            'mysql' => !$server->mysql_enabled,
            default => $server->ssh_enabled && !$server->apache_enabled,
        };
    }

    /**
     * Where to go after saving: the monitoring page the admin came from.
     */
    private function back(): string
    {
        $back = $this->request->get('back', false);

        return in_array($back, self::BACK_PAGES, true) ? $back : '/';
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
