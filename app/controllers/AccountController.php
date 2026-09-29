<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\Account;
use App\Models\Server;
use App\Services\AccountService;
use App\Services\ServerService;
use DomainException;

/**
 * Tracked accounts (admins): local, LDAP and shared logins with their
 * current password, reset date, rotation and where they're used. SSH
 * accounts (/admin/accounts/ssh) and database accounts
 * (/admin/accounts/mariadb) are listed and chosen separately.
 */
class AccountController extends Controller
{
    private const FIELDS = ['username', 'type', 'server_id', 'service', 'rotation_days', 'notes'];

    /**
     * List path per service.
     */
    private const LISTS = [Account::SERVICE_SSH => '/admin/accounts/ssh', Account::SERVICE_MYSQL => '/admin/accounts/mariadb'];

    private readonly AccountService $accounts;

    public function __construct()
    {
        parent::__construct();

        $this->accounts = new AccountService();
    }

    public function home()
    {
        $this->response->redirect(self::LISTS[Account::SERVICE_SSH]);
    }

    public function ssh()
    {
        $this->renderList(Account::SERVICE_SSH);
    }

    public function mariadb()
    {
        $this->renderList(Account::SERVICE_MYSQL);
    }

    /**
     * Import a MariaDB server's users ('user'@'%' only) as database accounts, without passwords.
     */
    public function importUsers()
    {
        $server = Server::query()->find((int) $this->request->get('server_id'));

        if (!$server instanceof Server) {
            $this->response->withFlash('error', 'Choose a MariaDB server to import users from.')->redirect(self::LISTS[Account::SERVICE_MYSQL]);

            return;
        }

        try {
            $result = $this->accounts->importMysqlUsers($this->authContext()->user, $server);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect(self::LISTS[Account::SERVICE_MYSQL]);

            return;
        }

        $parts = [count($result['added']) === 0 ? 'No new users' : 'Imported ' . implode(', ', $result['added'])];

        if ($result['existing'] !== []) {
            $parts[] = 'already tracked: ' . implode(', ', $result['existing']);
        }

        if ($result['ignored'] > 0) {
            $parts[] = "{$result['ignored']} ignored (roles, or a host other than '%')";
        }

        $this->response->withFlash('notice', "From {$server->name}: " . implode('; ', $parts) . '.' . ($result['added'] !== [] ? ' They have no password here, so they can\'t be used to log in until you record one.' : ''))
            ->redirect(self::LISTS[Account::SERVICE_MYSQL]);
    }

    /**
     * ?service=mysql adds a database account; otherwise an SSH account.
     */
    public function create()
    {
        $service = $this->request->get('service') === Account::SERVICE_MYSQL ? Account::SERVICE_MYSQL : Account::SERVICE_SSH;

        $this->renderForm(new Account(['type' => Account::TYPE_SHARED, 'service' => $service]));
    }

    public function store()
    {
        $input = $this->input();
        $input['password'] = (string) $this->request->get('password', false);
        $input['password_changed_at'] = $this->request->get('password_changed_at', false);

        try {
            $account = $this->accounts->create($this->authContext()->user, $input);
        } catch (DomainException $e) {
            $this->renderForm(new Account($this->input()), $e->getMessage());

            return;
        }

        $this->response->withFlash('notice', "Added {$account->username}.")->redirect("/admin/accounts/{$account->id}");
    }

    public function show($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account !== null) {
            $this->renderShow($account, notice: $this->request->flash('notice'), error: $this->request->flash('error'));
        }
    }

    public function edit($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account !== null) {
            $this->renderForm($account);
        }
    }

    public function update($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account === null) {
            return;
        }

        try {
            $this->accounts->update($this->authContext()->user, $account, $this->input());
        } catch (DomainException $e) {
            $this->renderForm($account->fill($this->input()), $e->getMessage());

            return;
        }

        $this->response->withFlash('notice', 'Saved.')->redirect("/admin/accounts/{$account->id}");
    }

    public function recordPassword($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account === null) {
            return;
        }

        try {
            $this->accounts->recordPassword(
                $this->authContext()->user,
                $account,
                (string) $this->request->get('password', false),
                $this->request->get('password_changed_at', false),
            );
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/admin/accounts/{$account->id}");

            return;
        }

        $this->response->withFlash('notice', 'New password recorded.')->redirect("/admin/accounts/{$account->id}");
    }

    public function reveal($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account === null) {
            return;
        }

        try {
            $proof = $this->request->get('passkey_confirmed') && (new \App\Services\AuthSessionService())->takePasskeyConfirmation()
                ? ['confirmed' => true]
                : ['password' => (string) $this->request->get('password', false), 'code' => (string) $this->request->get('code', false)];
            $password = $this->accounts->reveal($this->authContext()->user, $account, $proof, $this->clientIp());
        } catch (InvalidCredentialsException) {
            $this->renderShow($account, error: "That didn't match (a code can only be used once: wait for the app's next one); the password was not revealed.");

            return;
        } catch (TooManyAttemptsException $e) {
            $this->response->withHeader('Retry-After', (string) $e->retryAfter);
            $this->renderShow($account, error: 'Too many attempts. Try again in ' . $this->retryMinutes($e->retryAfter) . ' minute(s).', status: 429);

            return;
        } catch (DomainException $e) {
            $this->renderShow($account, error: $e->getMessage());

            return;
        }

        $this->response->withHeader('Cache-Control', 'no-store');
        $this->renderShow($account, revealed: $password);
    }

    public function delete($id)
    {
        $account = $this->findOrRedirect($id);

        if ($account === null) {
            return;
        }

        try {
            $this->accounts->delete($this->authContext()->user, $account);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect("/admin/accounts/{$account->id}");

            return;
        }

        $this->response->withFlash('notice', "Deleted {$account->username}.")->redirect(self::LISTS[$account->serviceName()]);
    }

    private function renderList(string $service): void
    {
        $this->accounts->syncServers(new ServerService());

        $this->response->view('accounts.index', [
            'auth' => $this->authContext(),
            'accounts' => $this->accounts->all($service),
            'area' => $service,
            'service' => $this->accounts,
            'mysqlServers' => $service === Account::SERVICE_MYSQL ? array_values(array_filter((new ServerService())->all(), fn (Server $s) => $s->mysql_enabled)) : [],
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
        ]);
    }

    private function renderShow(Account $account, ?string $revealed = null, ?string $notice = null, ?string $error = null, int $status = 200): void
    {
        $account->load(['servers', 'homeServer', 'originServer', 'originUser']);

        $this->response->view('accounts.show', [
            'auth' => $this->authContext(),
            'account' => $account,
            'service' => $this->accounts,
            'reveals' => $this->accounts->reveals($account),
            'revealed' => $revealed,
            'usable' => (new \App\Services\LoginMethodService())->usable($this->authContext()->user),
            'passkeysHere' => $this->site() !== null,
            'notice' => $notice,
            'error' => $error,
        ], $status);
    }

    private function renderForm(Account $account, ?string $error = null): void
    {
        $this->response->view('accounts.form', [
            'auth' => $this->authContext(),
            'account' => $account,
            'servers' => (new ServerService())->all(),
            'error' => $error,
        ], $error === null ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $input[$field] = $this->request->get($field, false);
        }

        return $input;
    }

    private function findOrRedirect($id): ?Account
    {
        $account = Account::query()->find((int) $id);

        if (!$account instanceof Account) {
            $this->response->withFlash('error', 'That account no longer exists.')->redirect(self::LISTS[Account::SERVICE_SSH]);

            return null;
        }

        return $account;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
