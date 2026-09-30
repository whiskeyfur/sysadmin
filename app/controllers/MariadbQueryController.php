<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\AuthorizationException;
use App\Models\MariadbQuery;
use App\Services\MariadbQueryService;
use App\Services\QueryAccountService;
use DomainException;

/**
 * The MariaDB multi-server query tool (/mariadb/query, everyone signed in): each user keeps a private
 * list of database logins (validated on entry, each for the servers it works on), picks servers and a
 * login for each, runs one statement, and sees the results collated. See MariadbQueryService and
 * QueryAccountService.
 */
class MariadbQueryController extends Controller
{
    public function index()
    {
        $this->show();
    }

    /**
     * POST /mariadb/query {servers[], account[server id], database, sql, limit, confirm}
     */
    public function run()
    {
        $user = $this->authContext()->user;
        $ticked = array_map('intval', (array) ($this->request->get('servers', false) ?? []));
        $choices = (array) ($this->request->get('account', false) ?? []);
        $plan = [];

        foreach ($ticked as $id) {
            $plan[$id] = (string) ($choices[$id] ?? '');
        }

        $input = [
            'plan' => $plan,
            'database' => trim((string) $this->request->get('database', false)),
            'sql' => (string) $this->request->get('sql', false),
            'limit' => (int) ($this->request->get('limit', false) ?: MariadbQueryService::DEFAULT_LIMIT),
        ];

        try {
            $result = (new MariadbQueryService())->run($user, $plan, $input['database'], $input['sql'], $input['limit'], (string) $this->request->get('confirm', false) === '1');
        } catch (DomainException|AuthorizationException $e) {
            $this->show(error: $e->getMessage(), input: $input);

            return;
        }

        $this->show(error: $result['auth_failed'] ? 'A server refused one of your logins; see the outcome below (the other servers for that login weren\'t tried). Check the account, or test it again.' : null, input: $input, result: $result);
    }

    /**
     * POST /mariadb/query/accounts {label, username, password, servers[]}: add an account, if its login
     * works on every server chosen.
     */
    public function addAccount()
    {
        $this->saveAccount(null);
    }

    /**
     * GET /mariadb/query/accounts/{id}
     */
    public function editAccount($id)
    {
        try {
            $account = (new QueryAccountService())->find($this->authContext()->user, (int) $id);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/mariadb/query');

            return;
        }

        $this->showAccount($account);
    }

    /**
     * POST /mariadb/query/accounts/{id} (a blank password keeps the stored one)
     */
    public function updateAccount($id)
    {
        try {
            $account = (new QueryAccountService())->find($this->authContext()->user, (int) $id);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/mariadb/query');

            return;
        }

        $this->saveAccount($account);
    }

    /**
     * POST /mariadb/query/accounts/{id}/test
     */
    public function testAccount($id)
    {
        $service = new QueryAccountService();

        try {
            $account = $service->find($this->authContext()->user, (int) $id);
            $results = $service->test($this->authContext()->user, $account);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/mariadb/query');

            return;
        }

        $refused = array_filter($results, fn ($r) => !$r['ok']);
        $this->response->withFlash($refused === [] ? 'notice' : 'error', $refused === []
            ? "{$account->label}: the login works on all " . count($results) . ' of its servers.'
            : "{$account->label}: refused by " . implode(', ', array_map(fn ($r) => $r['server']->name, $refused)) . '. Those servers are left out of the query form until it works; edit the account to fix it.')
            ->redirect('/mariadb/query#accounts');
    }

    /**
     * POST /mariadb/query/accounts/{id}/delete
     */
    public function deleteAccount($id)
    {
        $service = new QueryAccountService();

        try {
            $account = $service->find($this->authContext()->user, (int) $id);
            $service->delete($this->authContext()->user, $account);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/mariadb/query');

            return;
        }

        $this->response->withFlash('notice', "Deleted {$account->label} from your accounts.")->redirect('/mariadb/query#accounts');
    }

    /**
     * POST /mariadb/query/unthrottle: an admin lifts their own refused-login wait.
     */
    public function clearThrottle()
    {
        try {
            $cleared = (new MariadbQueryService())->clearThrottle($this->authContext()->user);
        } catch (AuthorizationException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/mariadb/query');

            return;
        }

        $this->response->withFlash('notice', "The wait is cleared ($cleared refused " . ($cleared === 1 ? 'login' : 'logins') . ' no longer counted).')->redirect('/mariadb/query');
    }

    private function saveAccount(?\App\Models\QueryAccount $account): void
    {
        $input = [
            'label' => (string) $this->request->get('label', false),
            'username' => (string) $this->request->get('username', false),
            'password' => (string) $this->request->get('password', false),
            'servers' => array_map('intval', (array) ($this->request->get('servers', false) ?? [])),
        ];

        try {
            $saved = (new QueryAccountService())->save($this->authContext()->user, $account, $input);
        } catch (DomainException $e) {
            $account === null ? $this->show(accountError: $e->getMessage(), accountInput: $input) : $this->showAccount($account, $e->getMessage(), $input);

            return;
        }

        if ($saved['account'] === null) {
            // Not saved: which servers refused it (the password is never sent back to the form).
            $refused = array_filter($saved['results'], fn ($r) => !$r['ok']);
            $error = 'Not saved: the login has to work on every server chosen, and ' . implode(', ', array_map(fn ($r) => $r['server']->name, $refused)) . ' refused it or couldn\'t be reached. Untick them, or check the username and password.';
            $account === null ? $this->show(accountError: $error, accountInput: $input, tested: $saved['results']) : $this->showAccount($account, $error, $input, $saved['results']);

            return;
        }

        $this->response->withFlash('notice', "Saved {$saved['account']->label}: the login works on all " . count($saved['results']) . ' of its servers.')->redirect('/mariadb/query#accounts');
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array{server: \App\Models\Server, ok: bool, message: string}>|null $tested
     */
    private function showAccount(\App\Models\QueryAccount $account, ?string $error = null, array $input = [], ?array $tested = null): void
    {
        $this->response->view('mariadb.account', [
            'auth' => $this->authContext(),
            'account' => $account,
            'servers' => (new MariadbQueryService())->servers(),
            'input' => $input,
            'tested' => $tested,
            'error' => $error,
            'throttled' => (new MariadbQueryService())->throttled($this->authContext()->user),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $result
     * @param array<string, mixed> $accountInput
     * @param list<array{server: \App\Models\Server, ok: bool, message: string}>|null $tested
     */
    private function show(?string $error = null, array $input = [], ?array $result = null, ?string $accountError = null, array $accountInput = [], ?array $tested = null): void
    {
        $user = $this->authContext()->user;

        $this->response->view('mariadb.query', [
            'auth' => $this->authContext(),
            'servers' => (new MariadbQueryService())->servers(),
            'accounts' => (new QueryAccountService())->forUser($user),
            'input' => $input,
            'result' => $result,
            'accountError' => $accountError,
            // Never the password.
            'accountInput' => array_diff_key($accountInput, ['password' => true]),
            'tested' => $tested,
            'recent' => MariadbQuery::query()->where('user_id', $user->id)->orderByDesc('id')->limit(20)->get()->all(),
            'notice' => $this->request->flash('notice'),
            'error' => $error ?? $this->request->flash('error'),
            'throttled' => (new MariadbQueryService())->throttled($user),
        ]);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
