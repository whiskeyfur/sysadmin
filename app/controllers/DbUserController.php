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
     * The most changes one "Execute" runs.
     */
    public const MAX_QUEUE = 50;

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

        if (($refused = $this->confirmIdentity(codes: false, passwordField: 'acct_password')) !== null) {
            $this->showIndex($refused, $input);

            return;
        }

        try {
            $results = (new DbUserManagerService())->create($this->authContext()->user, $input['servers'], $input['username'], $input['host'], (string) $this->request->get('db_password', false), $input['database'], $input['level'], $input['track']);
        } catch (DomainException|AuthorizationException $e) {
            $this->showIndex($e->getMessage(), $input);

            return;
        }

        $this->showAccount($input['username'], $input['host'], [['what' => 'Create ' . DbUserManagerService::name($input['username'], $input['host']), 'results' => $results, 'error' => null]]);
    }

    /**
     * GET /mariadb/users/account?user=&host=
     */
    public function account()
    {
        $this->showAccount((string) $this->request->get('user', false), (string) $this->request->get('host', false));
    }

    /**
     * POST /mariadb/users/account {user, host, action: queue|drop, ...}: "queue" runs the queued changes
     * (changes[i][action] = grant|revoke|password, with servers[], database, level, db_password, track),
     * in order, after one confirmation; "drop" (servers[]) drops the account.
     */
    public function change()
    {
        $user = (string) $this->request->get('user', false);
        $host = (string) $this->request->get('host', false);
        $action = (string) $this->request->get('action', false);
        $changes = $action === 'queue'
            ? array_values(array_filter((array) ($this->request->get('changes', false) ?? []), 'is_array'))
            : [['action' => $action, 'servers' => $this->request->get('servers', false) ?? []]];

        if ($changes === [] || count($changes) > self::MAX_QUEUE) {
            $this->showAccount($user, $host, error: $changes === [] ? 'Nothing is queued.' : 'At most ' . self::MAX_QUEUE . ' changes at a time.');

            return;
        }

        if (($refused = $this->confirmIdentity(codes: false, passwordField: 'acct_password')) !== null) {
            $this->showAccount($user, $host, error: $refused);

            return;
        }

        $service = new DbUserManagerService();
        $admin = $this->authContext()->user;
        $outcomes = [];

        // Each change on its own: one that's refused (e.g. a password too short) doesn't stop the rest.
        foreach ($changes as $change) {
            $servers = array_values(array_map('intval', (array) ($change['servers'] ?? [])));
            $database = trim((string) ($change['database'] ?? '')) ?: '*';
            $what = match ((string) ($change['action'] ?? '')) {
                'password' => 'Change the password',
                'grant' => 'Set ' . strtolower(DbUserManagerService::LEVELS[(string) ($change['level'] ?? '')][0] ?? '?') . " access on $database",
                'revoke' => "Remove access on $database",
                'drop' => 'Drop the account',
                default => 'Unknown change',
            };

            try {
                $results = match ((string) ($change['action'] ?? '')) {
                    'password' => $service->setPassword($admin, $servers, $user, $host, (string) ($change['db_password'] ?? ''), (string) ($change['track'] ?? '') === '1'),
                    'grant' => $service->grant($admin, $servers, $user, $host, $database, (string) ($change['level'] ?? '')),
                    'revoke' => $service->revoke($admin, $servers, $user, $host, $database),
                    'drop' => $service->drop($admin, $servers, $user, $host),
                    default => throw new DomainException('Not a change this page makes.'),
                };
                $outcomes[] = ['what' => $what, 'results' => $results, 'error' => null];
            } catch (DomainException|AuthorizationException $e) {
                $outcomes[] = ['what' => $what, 'results' => [], 'error' => $e->getMessage()];
            }
        }

        $this->showAccount($user, $host, $outcomes);
    }

    /**
     * GET /mariadb/users/names?servers[]=&db= (JSON): suggestions for the database fields, the chosen
     * servers' databases, or with db the tables and views in it.
     */
    public function names()
    {
        $servers = array_values(array_map('intval', (array) ($this->request->get('servers', false) ?? [])));
        $database = trim((string) $this->request->get('db', false));

        try {
            $names = (new DbUserManagerService())->names($this->authContext()->user, $servers, $database === '' ? null : mb_substr($database, 0, 64));
        } catch (AuthorizationException $e) {
            $this->response->json(['error' => $e->getMessage()], 403);

            return;
        }

        $this->response->json(['names' => $names]);
    }

    /**
     * @return array{servers: list<int>, username: string, host: string, database: string, level: string, track: bool}
     */
    private function input(): array
    {
        return [
            'servers' => array_values(array_map('intval', (array) ($this->request->get('servers', false) ?? []))),
            'username' => trim((string) $this->request->get('db_username', false)),
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
     * @param list<array{what: string, results: list<array{server: \App\Models\Server, ok: bool, message: string}>, error: ?string}>|null $outcomes
     */
    private function showAccount(string $user, string $host, ?array $outcomes = null, ?string $error = null): void
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
            'outcomes' => $outcomes,
            'error' => $error,
            'changes' => DbUserChange::query()->with(['user', 'server'])->where('account', DbUserManagerService::name(trim($user), trim($host)))->orderByDesc('id')->limit(30)->get()->all(),
        ] + $this->confirmFields());
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
