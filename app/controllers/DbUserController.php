<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\AuthorizationException;
use App\Models\DbUserChange;
use App\Services\DbUserManagerService;
use DomainException;

/**
 * The database account manager (MariaDB › Database users, admins): accounts across the servers whose
 * monitoring account may manage them, created, changed and dropped on several servers at once. Every
 * change needs a fresh check with one of the admin's sign-in methods. See DbUserManagerService.
 */
class DbUserController extends Controller
{
    use ConfirmsIdentity;

    /**
     * GET /mariadb/users
     */
    public function index()
    {
        $this->showIndex();
    }

    /**
     * POST /mariadb/users {servers[], username, host, password, database, level, track}
     */
    public function create()
    {
        $input = $this->input();

        if (($refused = $this->confirmIdentity()) !== null) {
            $this->showIndex($refused, $input);

            return;
        }

        try {
            $results = (new DbUserManagerService())->create($this->authContext()->user, $input['servers'], $input['username'], $input['host'], (string) $this->request->get('password', false), $input['database'], $input['level'], $input['track']);
        } catch (DomainException|AuthorizationException $e) {
            $this->showIndex($e->getMessage(), $input);

            return;
        }

        $this->showAccount($input['username'], $input['host'], 'Create ' . DbUserManagerService::name($input['username'], $input['host']), $results);
    }

    /**
     * GET /mariadb/users/account?user=&host=
     */
    public function account()
    {
        $this->showAccount((string) $this->request->get('user', false), (string) $this->request->get('host', false));
    }

    /**
     * POST /mariadb/users/account {user, host, action: password|grant|revoke|drop, servers[], ...}
     */
    public function change()
    {
        $user = (string) $this->request->get('user', false);
        $host = (string) $this->request->get('host', false);
        $action = (string) $this->request->get('action', false);
        $input = $this->input();

        if (($refused = $this->confirmIdentity()) !== null) {
            $this->showAccount($user, $host, null, null, $refused);

            return;
        }

        $service = new DbUserManagerService();
        $admin = $this->authContext()->user;

        try {
            [$what, $results] = match ($action) {
                'password' => ['Change the password', $service->setPassword($admin, $input['servers'], $user, $host, (string) $this->request->get('password', false), $input['track'])],
                'grant' => ['Set access on ' . $input['database'], $service->grant($admin, $input['servers'], $user, $host, $input['database'], $input['level'])],
                'revoke' => ['Remove access on ' . $input['database'], $service->revoke($admin, $input['servers'], $user, $host, $input['database'])],
                'drop' => ['Drop the account', $service->drop($admin, $input['servers'], $user, $host)],
                default => throw new DomainException('Choose a change.'),
            };
        } catch (DomainException|AuthorizationException $e) {
            $this->showAccount($user, $host, null, null, $e->getMessage());

            return;
        }

        $this->showAccount($user, $host, $what, $results);
    }

    /**
     * @return array{servers: list<int>, username: string, host: string, database: string, level: string, track: bool}
     */
    private function input(): array
    {
        return [
            'servers' => array_values(array_map('intval', (array) ($this->request->get('servers', false) ?? []))),
            'username' => trim((string) $this->request->get('username', false)),
            'host' => trim((string) $this->request->get('host', false)) ?: '%',
            'database' => trim((string) $this->request->get('database', false)) ?: '*',
            'level' => (string) $this->request->get('level', false),
            'track' => (string) $this->request->get('track', false) === '1',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function showIndex(?string $error = null, array $input = []): void
    {
        $service = new DbUserManagerService();
        $servers = $service->servers();
        $eligibility = [];

        foreach ($servers as $server) {
            $eligibility[$server->id] = $service->eligibility($server);
        }

        $this->response->view('mariadb.users', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'eligibility' => $eligibility,
            'accounts' => $service->accounts($servers),
            'changes' => DbUserChange::query()->with(['user', 'server'])->orderByDesc('id')->limit(30)->get()->all(),
            'input' => array_diff_key($input, ['password' => true]),
            'error' => $error,
        ] + $this->confirmFields());
    }

    /**
     * @param list<array{server: \App\Models\Server, ok: bool, message: string}>|null $results
     */
    private function showAccount(string $user, string $host, ?string $what = null, ?array $results = null, ?string $error = null): void
    {
        $service = new DbUserManagerService();
        $servers = array_values(array_filter($service->servers(), fn ($s) => $service->eligibility($s)['ok']));
        $grants = [];

        foreach ($servers as $server) {
            $grants[$server->id] = $service->grants($server, $user, $host);
        }

        $this->response->view('mariadb.user', [
            'auth' => $this->authContext(),
            'user' => $user,
            'host' => $host,
            'servers' => $servers,
            'grants' => $grants,
            'what' => $what,
            'results' => $results,
            'error' => $error,
            'changes' => DbUserChange::query()->with(['user', 'server'])->where('account', DbUserManagerService::name(trim($user), trim($host)))->orderByDesc('id')->limit(30)->get()->all(),
        ] + $this->confirmFields());
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
