<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\MariadbQuery;
use App\Services\AuthSessionService;
use App\Services\MariadbQueryService;
use DomainException;

/**
 * The MariaDB multi-server query tool (/mariadb/query, everyone signed in): log in with your own
 * database credentials (kept encrypted in the session until you log out of it, or of sys), pick servers,
 * run one statement, see the results collated. See MariadbQueryService.
 */
class MariadbQueryController extends Controller
{
    public function index()
    {
        $this->show();
    }

    /**
     * POST /mariadb/query/login {username, password} or, admins, {stored: 1}
     */
    public function login()
    {
        if ((string) $this->request->get('stored', false) === '1') {
            if (!$this->authContext()->isAdmin()) {
                $this->show(error: 'Only admins can use the servers\' stored accounts.');

                return;
            }

            (new AuthSessionService())->rememberDatabaseLogin($this->authContext()->user, '', '', stored: true);
            $this->response->withFlash('notice', 'Queries use each server\'s stored account for this session, until you log out of it.')->redirect('/mariadb/query');

            return;
        }

        $username = trim((string) $this->request->get('username', false));
        $password = (string) $this->request->get('password', false);

        if ($username === '' || mb_strlen($username) > 128) {
            $this->show(error: 'Type your database username.');

            return;
        }

        // Try it on every server now: kept if any accepts it; the ones that refused it are noted.
        try {
            $tested = (new MariadbQueryService())->testLogin($this->authContext()->user, $username, $password);
        } catch (DomainException $e) {
            $this->show(error: $e->getMessage());

            return;
        }

        $refused = [];

        foreach ($tested as $result) {
            if (!$result['ok']) {
                $refused[$result['server']->id] = $result['message'];
            }
        }

        if (count($refused) === count($tested)) {
            $this->show(error: "No server accepted the login as $username, so it wasn't kept. Check it and log in again.", tested: $tested);

            return;
        }

        (new AuthSessionService())->rememberDatabaseLogin($this->authContext()->user, $username, $password, refused: $refused);
        $works = count($tested) - count($refused);
        $this->response->withFlash('notice', "Logged in to MariaDB as $username: the login works on $works of " . count($tested) . ' ' . (count($tested) === 1 ? 'server' : 'servers')
            . ($refused === [] ? '.' : '; the ones that refused it are unticked below.'))->redirect('/mariadb/query');
    }

    /**
     * POST /mariadb/query/logout
     */
    public function logout()
    {
        (new AuthSessionService())->forgetDatabaseLogin();
        $this->response->withFlash('notice', 'Logged out of MariaDB: the login is gone from this session.')->redirect('/mariadb/query');
    }

    /**
     * POST /mariadb/query {servers[], database, sql, limit, confirm}
     */
    public function run()
    {
        $user = $this->authContext()->user;
        $sessions = new AuthSessionService();
        $login = $sessions->databaseLogin($user);
        $input = [
            'servers' => array_values(array_map('intval', (array) ($this->request->get('servers', false) ?? []))),
            'database' => trim((string) $this->request->get('database', false)),
            'sql' => (string) $this->request->get('sql', false),
            'limit' => (int) ($this->request->get('limit', false) ?: MariadbQueryService::DEFAULT_LIMIT),
        ];

        if ($login === null) {
            $this->show(error: 'Log in to MariaDB first.', input: $input);

            return;
        }

        try {
            $result = (new MariadbQueryService())->run($user, $input['servers'], $login['username'], $login['password'], $input['database'], $input['sql'], $input['limit'], (string) $this->request->get('confirm', false) === '1', $login['stored']);
        } catch (DomainException|\App\Exceptions\AuthorizationException $e) {
            $this->show(error: $e->getMessage(), input: $input);

            return;
        }

        $error = null;

        // Every server refused the login: it isn't kept, the next try asks for it again.
        if ($result['auth_failed']) {
            $sessions->forgetDatabaseLogin();
            $error = "Every server refused the login as {$login['username']}; log in again.";
        }

        $this->show(error: $error, input: $input, result: $result);
    }

    /**
     * @param array{servers?: list<int>, database?: string, sql?: string, limit?: int} $input
     * @param array<string, mixed>|null $result
     * @param list<array{server: \App\Models\Server, ok: bool, auth: bool, message: string}>|null $tested a log-in test that no server passed
     */
    private function show(?string $error = null, array $input = [], ?array $result = null, ?array $tested = null): void
    {
        $user = $this->authContext()->user;
        $login = (new AuthSessionService())->databaseLogin($user);
        $service = new MariadbQueryService();

        $this->response->view('mariadb.query', [
            'auth' => $this->authContext(),
            'servers' => $service->servers(),
            // Only the username goes to the page, never the password.
            'login' => $login === null ? null : ['stored' => $login['stored'], 'username' => $login['username'], 'since' => $login['since'], 'refused' => $login['refused']],
            'tested' => $tested,
            'input' => $input,
            'result' => $result,
            'recent' => MariadbQuery::query()->where('user_id', $user->id)->orderByDesc('id')->limit(20)->get()->all(),
            'notice' => $this->request->flash('notice'),
            'error' => $error ?? $this->request->flash('error'),
        ]);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
