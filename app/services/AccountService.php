<?php

namespace App\Services;

use App\Enums\LoginStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\Account;
use App\Models\PasswordReveal;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use Psr\Clock\ClockInterface;

/**
 * Tracked accounts, kept apart per service: SSH accounts (local system
 * users, LDAP, shared) and database accounts (local database users, LDAP,
 * shared) never mix. Local accounts belong to one server; LDAP and shared
 * ones can be used on several.
 * Each has a current password (encrypted, revealed to admins only after
 * they confirm with an authenticator code, every reveal logged), when it was last
 * reset and a rotation period. Tracks which accounts log into which servers
 * and when the app last used each one.
 *
 * Recording a password here doesn't change it anywhere else: the app tracks
 * passwords, it doesn't set them.
 */
class AccountService
{
    public const STATUS_NONE = 'none';

    public const STATUS_OK = 'ok';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        private readonly SecretCipher $cipher = new SecretCipher(),
        private ?AuthService $auth = null,
        private readonly SettingsService $settings = new SettingsService(),
        private readonly ClockInterface $clock = new SystemClock(),
        private ?MysqlService $mysql = null,
    ) {
    }

    /**
     * @param string|null $service only SSH or only database accounts
     * @return list<Account>
     */
    public function all(?string $service = null): array
    {
        return Account::query()->with(['servers', 'homeServer', 'originServer'])->get()
            ->filter(fn (Account $a) => $service === null || $a->serviceName() === $service)
            ->sortBy(fn (Account $a) => [$this->statusRank($a), strtolower($a->username)])
            ->values()
            ->all();
    }

    /**
     * LDAP and shared accounts, plus the given server's own local accounts
     * for $service: the accounts that server can log in with.
     *
     * @return list<Account>
     */
    public function selectableFor(?Server $server, string $service = Account::SERVICE_SSH): array
    {
        return Account::query()->get()
            ->filter(fn (Account $a) => $a->type === Account::TYPE_LOCAL
                ? $server?->id !== null && $a->usableFor($server, $service)
                : $a->service === null || $a->service === $service)
            ->sortBy(fn (Account $a) => [$a->type, strtolower($a->username)])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws DomainException with a user-facing message
     */
    public function create(User $admin, array $input): Account
    {
        $this->requireAdmin($admin);
        $account = new Account(['origin' => Account::ORIGIN_MANUAL, 'origin_user_id' => $admin->id]);
        $this->fill($account, $input);
        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            $this->storePassword($account, $password, $this->date($input['password_changed_at'] ?? null));
        }

        return $account;
    }

    /**
     * Change details (not the password; see recordPassword()).
     *
     * @param array<string, mixed> $input
     *
     * @throws DomainException
     */
    public function update(User $admin, Account $account, array $input): void
    {
        $this->requireAdmin($admin);
        $this->fill($account, $input);
    }

    /**
     * Record a new current password, reset on $changedAt (default now).
     */
    public function recordPassword(User $admin, Account $account, string $password, mixed $changedAt = null): void
    {
        $this->requireAdmin($admin);

        if ($password === '') {
            throw new DomainException('Enter the new password.');
        }

        $this->storePassword($account, $password, $this->date($changedAt));
    }

    /**
     * Save a password without an admin check, for the app's own flows (SSH
     * setup storing the password it was given, a MySQL password entered on
     * the server form).
     */
    public function storePassword(Account $account, string $password, ?Carbon $changedAt = null): void
    {
        if (!$account->exists) {
            $account->save();
        }

        $account->password = $this->cipher->encrypt($password, $this->context($account));
        $account->password_changed_at = $changedAt ?? Carbon::instance($this->clock->now());
        $account->save();
    }

    /**
     * The decrypted password, for the app's own logins. Never for views: use reveal().
     */
    public function password(Account $account): ?string
    {
        return $account->password === null ? null : $this->cipher->decrypt($account->password, $this->context($account));
    }

    /**
     * Show an admin the password after they prove it's them (AuthService::confirm(): their password or
     * an authenticator code, or ['confirmed' => true] after a passkey check made just before); logged.
     *
     * @param array<string, mixed> $proof
     *
     * @throws InvalidCredentialsException if the proof is wrong
     * @throws TooManyAttemptsException
     * @throws DomainException if no password is stored
     */
    public function reveal(User $admin, Account $account, array $proof, string $ip): string
    {
        $this->requireAdmin($admin);

        if (($proof['confirmed'] ?? false) !== true) {
            $this->auth ??= new AuthService(cipher: $this->cipher);
            $result = $this->auth->confirm($admin, $proof, $ip);

            if ($result->status === LoginStatus::TooManyAttempts) {
                throw new TooManyAttemptsException($result->retryAfter);
            }

            if ($result->status !== LoginStatus::Success) {
                throw new InvalidCredentialsException("That didn't match.");
            }
        }

        $password = $this->password($account);

        if ($password === null) {
            throw new DomainException('No password is stored for this account.');
        }

        PasswordReveal::query()->create([
            'account_id' => $account->id,
            'user_id' => $admin->id,
            'revealed_at' => Carbon::instance($this->clock->now()),
        ]);

        return $password;
    }

    /**
     * @throws DomainException if a server still logs in with it
     */
    public function delete(User $admin, Account $account): void
    {
        $this->requireAdmin($admin);
        $users = Server::query()->where('ssh_account_id', $account->id)->orWhere('mysql_account_id', $account->id)->pluck('name');

        if ($users->isNotEmpty()) {
            throw new DomainException('Servers still log in with this account: ' . $users->implode(', ') . '. Change their SSH or database account first.');
        }

        $account->getConnection()->transaction(function () use ($account) {
            $account->servers()->detach();
            PasswordReveal::query()->where('account_id', $account->id)->delete();
            $account->delete();
        });
    }

    public function dueAt(Account $account): ?Carbon
    {
        if ($account->rotation_days === null || $account->password_changed_at === null) {
            return null;
        }

        return $account->password_changed_at->copy()->addDays($account->rotation_days);
    }

    /**
     * none (no rotation), ok, due_soon, overdue, or unknown (rotation set but
     * no reset date recorded).
     */
    public function status(Account $account): string
    {
        if ($account->rotation_days === null) {
            return self::STATUS_NONE;
        }

        $due = $this->dueAt($account);

        if ($due === null) {
            return self::STATUS_UNKNOWN;
        }

        $now = Carbon::instance($this->clock->now());

        return match (true) {
            $due->lessThanOrEqualTo($now) => self::STATUS_OVERDUE,
            $due->lessThanOrEqualTo($now->copy()->addDays($this->settings->accountWarningDays())) => self::STATUS_DUE_SOON,
            default => self::STATUS_OK,
        };
    }

    /**
     * The account a server logs in with over SSH; for older servers, a local
     * account is created from the SSH username (see syncServers()).
     */
    public function forServer(Server $server): ?Account
    {
        return $this->accountFor($server, Account::SERVICE_SSH);
    }

    /**
     * The account a server logs into its database with; for older servers, a
     * local database user is created from the MySQL username.
     */
    public function forMysql(Server $server): ?Account
    {
        return $this->accountFor($server, Account::SERVICE_MYSQL);
    }

    /**
     * Point a server's SSH or database login at an account: an existing
     * LDAP/shared (or its own local) account by id, or else a local account
     * for $username, created if needed. Keeps the server's username in step.
     *
     * @throws DomainException if the chosen account can't be used on this server
     */
    public function assign(Server $server, ?int $accountId, string $username, string $service = Account::SERVICE_SSH): Account
    {
        if ($accountId !== null) {
            $account = Account::query()->find($accountId);

            if (!$account instanceof Account || !$account->usableFor($server, $service)) {
                throw new DomainException('That account can\'t be used on this server.');
            }

            return $this->link($server, $account, $service);
        }

        return $this->assignLocal($server, $username, $service);
    }

    /**
     * Note that the app just logged into a server with its SSH or database account.
     */
    public function recordUse(Server $server, string $service = Account::SERVICE_SSH): void
    {
        $id = $service === Account::SERVICE_MYSQL ? $server->mysql_account_id : $server->ssh_account_id;
        $account = $id === null ? null : Account::query()->find($id);

        if ($account instanceof Account) {
            $account->servers()->syncWithoutDetaching([$server->id => ['last_used_at' => Carbon::instance($this->clock->now())]]);
        }
    }

    /**
     * Give every SSH and database login an account, move passwords stored
     * on servers (before accounts existed) into those accounts, and give
     * LDAP/shared accounts from before services existed their service.
     * Idempotent.
     */
    public function syncServers(ServerService $servers): void
    {
        $this->assignServices();

        foreach (Server::query()->where('ssh_enabled', true)->orWhere('mysql_enabled', true)->get() as $server) {
            if ($server->ssh_enabled) {
                $this->adopt($this->forServer($server), $server, 'ssh_password', fn () => $servers->legacySshPassword($server));
            }

            if ($server->mysql_enabled) {
                $this->adopt($this->forMysql($server), $server, 'mysql_password', fn () => $servers->legacyMysqlPassword($server));
            }
        }
    }

    /**
     * Track a MariaDB server's user accounts as local database accounts of
     * that server. Only accounts for any host ('user'@'%') are imported;
     * roles and host-specific accounts are left out. No password is set:
     * an imported account can't be used to log in until an admin records
     * one (Account::canLogIn()). Users already tracked are left alone.
     *
     * @return array{added: list<string>, existing: list<string>, ignored: int}
     *
     * @throws DomainException when the server has no MariaDB monitoring or its users can't be read
     */
    public function importMysqlUsers(User $admin, Server $server): array
    {
        $this->requireAdmin($admin);

        if (!$server->mysql_enabled) {
            throw new DomainException("{$server->name} has no MariaDB monitoring to import users from.");
        }

        $result = ['added' => [], 'existing' => [], 'ignored' => 0];

        foreach ($this->mysqlUsers($server) as $user) {
            if ($user['host'] !== '%' || $user['role'] || $user['name'] === '') {
                $result['ignored']++;

                continue;
            }

            $tracked = Account::query()->where('type', Account::TYPE_LOCAL)->where('server_id', $server->id)->where('username', $user['name'])->get()
                ->contains(fn (Account $a) => $a->serviceName() === Account::SERVICE_MYSQL);

            if ($tracked) {
                $result['existing'][] = $user['name'];

                continue;
            }

            $account = Account::query()->create([
                'username' => $user['name'],
                'type' => Account::TYPE_LOCAL,
                'server_id' => $server->id,
                'service' => Account::SERVICE_MYSQL,
                'origin' => Account::ORIGIN_IMPORT,
                'origin_server_id' => $server->id,
                'origin_user_id' => $admin->id,
                'origin_detail' => "'{$user['name']}'@'%'" . ($user['plugin'] !== '' ? ", {$user['plugin']}" : ''),
            ]);
            // Not linked as "used on" the server: nothing logs in with it yet.
            $result['added'][] = $user['name'];
        }

        return $result;
    }

    /**
     * The server's MariaDB (or MySQL) accounts.
     *
     * @return list<array{name: string, host: string, plugin: string, role: bool}>
     *
     * @throws DomainException
     */
    protected function mysqlUsers(Server $server): array
    {
        $this->mysql ??= new MysqlService(new ServerService($this->cipher));

        try {
            $pdo = $this->mysql->connect($server);
            // SELECT * copes with MariaDB (is_role) and MySQL (no is_role) alike.
            $rows = $pdo->query('SELECT * FROM mysql.user')?->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (ServerConnectionException $e) {
            throw new DomainException("Couldn't connect to {$server->name}'s MariaDB: " . $e->getMessage());
        } catch (\PDOException $e) {
            throw new DomainException(in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1044], true)
                ? "The monitoring user can't read mysql.user on {$server->name}; it needs SELECT on it (the usual GRANT SELECT ON *.* covers it)."
                : "Couldn't read {$server->name}'s users: " . $e->getMessage());
        }

        return array_values(array_map(fn (array $row) => [
            'name' => (string) ($row['User'] ?? $row['user'] ?? ''),
            'host' => (string) ($row['Host'] ?? $row['host'] ?? ''),
            'plugin' => (string) ($row['plugin'] ?? ''),
            'role' => strtoupper((string) ($row['is_role'] ?? 'N')) === 'Y',
        ], $rows));
    }

    /**
     * @return list<PasswordReveal>
     */
    public function reveals(Account $account, int $limit = 20): array
    {
        return PasswordReveal::query()->with('user')->where('account_id', $account->id)->get()
            ->sortByDesc('revealed_at')->take($limit)->values()->all();
    }

    /**
     * LDAP/shared accounts from before SSH and database accounts were kept
     * apart: one used only for database logins becomes a database account;
     * one used for both is split, the copy (same password, dates and notes)
     * taking over the database logins; the rest become SSH accounts.
     */
    private function assignServices(): void
    {
        /** @var list<Account> $legacy */
        $legacy = Account::query()->whereNull('service')->where('type', '!=', Account::TYPE_LOCAL)->get()->all();

        foreach ($legacy as $account) {
            $ssh = Server::query()->where('ssh_account_id', $account->id)->exists();
            $mysql = Server::query()->where('mysql_account_id', $account->id)->get();

            if ($mysql->isEmpty() || $ssh) {
                $account->service = Account::SERVICE_SSH;
                $account->save();
            }

            if ($mysql->isEmpty()) {
                continue;
            }

            $database = $account;

            if ($ssh) {
                $database = $account->replicate(['service']);
                $database->service = Account::SERVICE_MYSQL;
                $database->save();

                if ($account->password !== null) {
                    // Encrypted per account id: re-encrypt for the copy.
                    $this->storePassword($database, (string) $this->password($account), $account->password_changed_at);
                }

                // Servers it still logs into over SSH stay linked to the original.
                $account->servers()->detach($mysql->filter(fn (Server $server) => $server->ssh_account_id !== $account->id)->pluck('id')->all());
            } else {
                $database->service = Account::SERVICE_MYSQL;
                $database->save();
            }

            foreach ($mysql as $server) {
                $server->mysql_account_id = $database->id;
                $server->save();
                $database->servers()->syncWithoutDetaching([$server->id]);
            }
        }
    }

    private function accountFor(Server $server, string $service): ?Account
    {
        $mysql = $service === Account::SERVICE_MYSQL;

        if (!($mysql ? $server->mysql_enabled : $server->ssh_enabled)) {
            return null;
        }

        $id = $mysql ? $server->mysql_account_id : $server->ssh_account_id;
        $account = $id !== null ? Account::query()->find($id) : null;

        if ($account instanceof Account) {
            return $account;
        }

        return $this->assignLocal($server, (string) ($mysql ? $server->mysql_username : $server->ssh_username), $service);
    }

    /**
     * Move a password stored on the server (legacy column) into its account.
     *
     * @param callable(): ?string $legacy
     */
    private function adopt(?Account $account, Server $server, string $column, callable $legacy): void
    {
        if ($account === null || $server->{$column} === null) {
            return;
        }

        $password = $legacy();

        if ($password !== null && $account->password === null) {
            $this->storePassword($account, $password);
        }

        $server->{$column} = null;
        $server->save();
    }

    private function assignLocal(Server $server, string $username, string $service): Account
    {
        $accounts = Account::query()
            ->where('type', Account::TYPE_LOCAL)
            ->where('server_id', $server->id)
            ->where('username', $username)
            ->get();
        $account = $accounts->first(fn (Account $a) => $a->localService() === $service)
            ?? Account::query()->create([
                'username' => $username, 'type' => Account::TYPE_LOCAL, 'server_id' => $server->id, 'service' => $service,
                'origin' => Account::ORIGIN_SERVER, 'origin_server_id' => $server->id,
            ]);

        return $this->link($server, $account, $service);
    }

    private function link(Server $server, Account $account, string $service): Account
    {
        $account->servers()->syncWithoutDetaching([$server->id]);
        [$idColumn, $userColumn] = $service === Account::SERVICE_MYSQL ? ['mysql_account_id', 'mysql_username'] : ['ssh_account_id', 'ssh_username'];

        if ($server->{$idColumn} !== $account->id || $server->{$userColumn} !== $account->username) {
            $server->{$idColumn} = $account->id;
            $server->{$userColumn} = $account->username;
            $server->save();
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fill(Account $account, array $input): void
    {
        $username = trim((string) ($input['username'] ?? ''));
        $type = (string) ($input['type'] ?? $account->type ?? '');
        $serverId = filter_var($input['server_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $service = (string) ($input['service'] ?? $account->service ?? Account::SERVICE_SSH);
        $rotation = trim((string) ($input['rotation_days'] ?? ''));

        if ($username === '' || mb_strlen($username) > 256 || preg_match('/\s/', $username) === 1) {
            throw new DomainException('Enter the username (no spaces; e.g. deploy, CORP\\jsmith or jsmith@corp.example).');
        }

        if (!in_array($type, Account::TYPES, true)) {
            throw new DomainException('Choose the account type.');
        }

        if ($type === Account::TYPE_LOCAL) {
            if ($serverId === null || !Server::query()->whereKey($serverId)->exists()) {
                throw new DomainException('Choose the server this local account belongs to.');
            }

        } else {
            $serverId = null;
        }

        if (!in_array($service, Account::SERVICES, true)) {
            throw new DomainException('Choose whether it\'s an SSH or a database account.');
        }

        if ($account->exists && $account->serviceName() !== $service) {
            $users = Server::query()->where($service === Account::SERVICE_MYSQL ? 'ssh_account_id' : 'mysql_account_id', $account->id)->pluck('name');

            if ($users->isNotEmpty()) {
                throw new DomainException('Servers still log in with this account as a' . ($service === Account::SERVICE_MYSQL ? 'n SSH' : ' database') . ' account: ' . $users->implode(', ') . '. SSH and database accounts are kept apart; change those servers first.');
            }
        }

        $duplicates = Account::query()->where('type', $type)->where('username', $username)->where('id', '!=', $account->id ?? 0);

        if ($type === Account::TYPE_LOCAL) {
            $duplicates->where('server_id', $serverId);
        }

        if ($duplicates->get()->contains(fn (Account $a) => $a->serviceName() === $service)) {
            throw new DomainException('That account is already tracked.');
        }

        if ($rotation !== '' && filter_var($rotation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]) === false) {
            throw new DomainException('Rotation must be a whole number of days from 1 to 3650, or empty for none.');
        }

        $account->username = $username;
        $account->type = $type;
        $account->server_id = $serverId;
        $account->service = $service;
        $account->rotation_days = $rotation === '' ? null : (int) $rotation;
        $account->notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000) ?: null;

        if ($account->exists && $account->isDirty('server_id', 'service')) {
            // A local account moved to another server or service no longer logs into its old one.
            $this->releaseServers($account);
        }

        $account->save();

        // Servers log in with the account's current username.
        Server::query()->where('ssh_account_id', $account->id)->update(['ssh_username' => $username]);
        Server::query()->where('mysql_account_id', $account->id)->update(['mysql_username' => $username]);

        if ($type === Account::TYPE_LOCAL) {
            $account->servers()->syncWithoutDetaching([$serverId]);
        }
    }

    /**
     * Unlink a local account from the servers it logged into; those servers
     * get a fresh local account for the same username when next used.
     */
    private function releaseServers(Account $account): void
    {
        $account->servers()->detach();
        Server::query()->where('ssh_account_id', $account->id)->update(['ssh_account_id' => null]);
        Server::query()->where('mysql_account_id', $account->id)->update(['mysql_account_id' => null]);
    }

    private function date(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, \App\Utils\LocalTime::zone());
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value || $date->isFuture()) {
            throw new DomainException('Enter the reset date as YYYY-MM-DD, not in the future.');
        }

        return $date->startOfDay()->utc();
    }

    private function statusRank(Account $account): int
    {
        return match ($this->status($account)) {
            self::STATUS_OVERDUE => 0,
            self::STATUS_DUE_SOON => 1,
            self::STATUS_UNKNOWN => 2,
            default => 3,
        };
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage accounts.');
        }
    }

    private function context(Account $account): string
    {
        return 'account-password:' . $account->id;
    }
}
