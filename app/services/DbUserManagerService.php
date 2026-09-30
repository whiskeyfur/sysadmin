<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\Account;
use App\Models\DbUserChange;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;

/**
 * The database account manager (MariaDB › Database users, admins): create, change and drop
 * MariaDB/MySQL accounts on several servers at once, with access given as presets per database. It logs
 * in with each server's monitoring account, so it only works where that account may create users and
 * grant privileges (CREATE USER, or ALL, on *.* WITH GRANT OPTION: see canManage()).
 *
 * Every change is made per server, reported per server and logged (DbUserChange, never a password). The
 * monitoring login itself and the system accounts are never touched. Created accounts and new passwords
 * are tracked in MariaDB › Accounts (a local database account per server).
 */
class DbUserManagerService
{
    /**
     * Access presets: key => [label, privileges].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const LEVELS = [
        'read' => ['Read only', 'SELECT'],
        'write' => ['Read/write', 'SELECT, INSERT, UPDATE, DELETE'],
        'all' => ['Full', 'ALL PRIVILEGES'],
    ];

    /**
     * Accounts the manager never changes or drops.
     */
    public const PROTECTED_USERS = ['root', 'mariadb.sys', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'mysql', 'debian-sys-maint'];

    public const MIN_PASSWORD = 12;

    /**
     * The most names one suggestion list holds.
     */
    public const MAX_NAMES = 1000;

    /**
     * @var array<int, array{ok: bool, reason: string}>
     */
    private array $eligibility = [];

    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        private ?AccountService $accounts = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Whether the grants of the monitoring account (SHOW GRANTS FOR CURRENT_USER()) let it manage accounts:
     * on *.*, CREATE USER (or ALL PRIVILEGES), WITH GRANT OPTION.
     *
     * @param list<string> $grants
     */
    public static function canManage(array $grants): bool
    {
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s.*\bWITH\b.*\bGRANT\s+OPTION\b/is', $grant, $m) === 1
                && preg_match('/\bALL(\s+PRIVILEGES)?\b|\bCREATE\s+USER\b/i', $m[1]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The servers with MariaDB/MySQL monitoring, by name.
     *
     * @return list<Server>
     */
    public function servers(): array
    {
        /** @var list<Server> $servers */
        $servers = Server::query()->where('mysql_enabled', true)->orderBy('name')->get()->all();

        return $servers;
    }

    /**
     * Whether the manager can work on a server, and if not why.
     *
     * @return array{ok: bool, reason: string}
     */
    public function eligibility(Server $server): array
    {
        if (isset($this->eligibility[$server->id])) {
            return $this->eligibility[$server->id];
        }

        try {
            $pdo = $this->mysql->connect($server);
            $grants = array_map('strval', $pdo->query('SHOW GRANTS FOR CURRENT_USER()')?->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $result = self::canManage($grants)
                ? ['ok' => true, 'reason' => 'The monitoring account can create users and grant privileges.']
                : ['ok' => false, 'reason' => "The monitoring account ({$server->mysql_username}) can't create users and grant privileges: it needs CREATE USER (or ALL) on *.* WITH GRANT OPTION."];
        } catch (ServerConnectionException|PDOException $e) {
            $result = ['ok' => false, 'reason' => "Couldn't check: {$e->getMessage()}"];
        }

        return $this->eligibility[$server->id] = $result;
    }

    /**
     * Every account (not roles) on the eligible servers: 'user'@'host' => user, host and the servers it's on.
     *
     * @param list<Server> $servers
     * @return array<string, array{user: string, host: string, on: array<int, true>}>
     */
    public function accounts(array $servers): array
    {
        $accounts = [];

        foreach ($servers as $server) {
            if (!$this->eligibility($server)['ok']) {
                continue;
            }

            try {
                $rows = $this->mysql->connect($server)->query('SELECT * FROM mysql.user')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (ServerConnectionException|PDOException) {
                continue;
            }

            foreach ($rows as $row) {
                $user = (string) ($row['User'] ?? $row['user'] ?? '');
                $host = (string) ($row['Host'] ?? $row['host'] ?? '');

                if (strtoupper((string) ($row['is_role'] ?? 'N')) === 'Y') {
                    continue;
                }

                $key = self::name($user, $host);
                $accounts[$key] ??= ['user' => $user, 'host' => $host, 'on' => []];
                $accounts[$key]['on'][$server->id] = true;
            }
        }

        uksort($accounts, 'strnatcasecmp');

        return $accounts;
    }

    /**
     * What a SHOW GRANTS line is on, as the database fields write it ("*", "db" or "db.table"), when its
     * privileges can be removed here (REVOKE ALL on that target); null for the account's USAGE line,
     * role, proxy and routine grants, and names the fields don't take.
     */
    public static function grantTarget(string $grant): ?string
    {
        $name = '`((?:[^`]|``)+)`';

        if (preg_match('/^GRANT (.+?) ON (\*\.\*|' . $name . '\.(?:\*|' . $name . ')) TO /', $grant, $m) !== 1) {
            return null;
        }

        if (trim($m[1]) === 'USAGE' || str_starts_with(trim($m[1]), 'PROXY')) {
            return null;
        }

        if ($m[2] === '*.*') {
            return '*';
        }

        $schema = str_replace('``', '`', $m[3]);
        $table = isset($m[4]) ? str_replace('``', '`', $m[4]) : null;
        $target = $table === null ? $schema : "$schema.$table";

        try {
            self::target($target);
        } catch (DomainException) {
            return null;
        }

        return $target;
    }

    /**
     * Names for the database fields' suggestions, from the chosen servers the tool can manage (through
     * their monitoring accounts): the databases, or with $database the tables and views in it. Servers
     * that can't be read are skipped.
     *
     * @param list<int> $serverIds
     * @return list<string>
     *
     * @throws AuthorizationException for anyone but an admin
     */
    public function names(User $admin, array $serverIds, ?string $database = null): array
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage database accounts.');
        }

        $names = [];

        foreach ($this->servers() as $server) {
            if (!in_array($server->id, $serverIds, true) || !$this->eligibility($server)['ok']) {
                continue;
            }

            try {
                $statement = $this->mysql->connect($server)->prepare($database === null
                    ? 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA'
                    : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
                $statement->execute($database === null ? [] : [$database]);
                array_push($names, ...array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
            } catch (ServerConnectionException|PDOException) {
                continue;
            }
        }

        $names = array_values(array_unique($names));
        natcasesort($names);

        return array_slice(array_values($names), 0, self::MAX_NAMES);
    }

    /**
     * An account's grants on a server, or null when it doesn't exist there (or can't be read).
     *
     * @return list<string>|null
     */
    public function grants(Server $server, string $user, string $host): ?array
    {
        try {
            $pdo = $this->mysql->connect($server);
            $statement = $pdo->prepare('SHOW GRANTS FOR ?@?');
            $statement->execute([$user, $host]);

            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (ServerConnectionException|PDOException) {
            return null;
        }
    }

    /**
     * Create an account on each server, give it an access level, and (when $track) record it with its
     * password in Accounts. A blank password makes a socket login (MariaDB unix_socket, MySQL
     * auth_socket: the system user of the same name, over the local socket), for host localhost or %.
     *
     * @param list<int> $serverIds
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    public function create(User $admin, array $serverIds, string $user, string $host, string $password, string $database, string $level, bool $track): array
    {
        [$user, $host] = [trim($user), trim($host)];
        // No password: the account signs in by socket (the system user of the same name, over the local
        // socket), which only ever arrives as localhost.
        $socket = $password === '';

        if ($socket && !in_array($host, ['localhost', '%'], true)) {
            throw new DomainException('Without a password the account signs in over the local socket (as the system user of the same name), which always comes from localhost: use the host localhost (or %), or give it a password.');
        }

        if (!$socket) {
            $this->checkPassword($password, $user);
        }

        $target = self::target($database);
        $privileges = $this->privileges($level);

        return $this->onEach($admin, $serverIds, 'create', $user, $host, self::LEVELS[$level][0] . ' on ' . $this->databaseLabel($database) . ($socket ? ', socket login' : ''), function (PDO $pdo, Server $server) use ($user, $host, $password, $socket, $target, $privileges, $track, $admin) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?');
            $exists->execute([$user, $host]);

            if ((int) $exists->fetchColumn() > 0) {
                throw new DomainException('It already exists on this server.');
            }

            $account = $this->quoted($pdo, $user, $host);
            // MariaDB's socket plugin is unix_socket, MySQL's auth_socket.
            $plugin = $socket ? (stripos((string) $pdo->query('SELECT VERSION()')?->fetchColumn(), 'mariadb') !== false ? 'VIA unix_socket' : 'WITH auth_socket') : null;
            $pdo->exec("CREATE USER $account IDENTIFIED " . ($plugin ?? 'BY ' . $pdo->quote($password)));
            $pdo->exec("GRANT $privileges ON $target TO $account");

            if ($track) {
                $this->track($admin, $server, $user, $host, $socket ? null : $password);
            }

            return 'Created' . ($socket ? ' (signs in by socket: the system user ' . $user . ' on ' . $server->name . ', no password)' : '') . ', with ' . strtolower($this->levelLabelFor($privileges)) . ' access' . ($track ? '; tracked in Accounts.' : '.');
        });
    }

    /**
     * @param list<int> $serverIds
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    public function setPassword(User $admin, array $serverIds, string $user, string $host, string $password, bool $track): array
    {
        [$user, $host] = [trim($user), trim($host)];
        $this->checkPassword($password, $user);

        return $this->onEach($admin, $serverIds, 'password', $user, $host, null, function (PDO $pdo, Server $server) use ($user, $host, $password, $track, $admin) {
            $pdo->exec('ALTER USER ' . $this->quoted($pdo, $user, $host) . ' IDENTIFIED BY ' . $pdo->quote($password));

            if ($track) {
                $this->track($admin, $server, $user, $host, $password);
            }

            return 'Password changed' . ($track ? '; tracked in Accounts.' : '.');
        });
    }

    /**
     * Set the account's access to one database (or all): what it had there is revoked, then the level
     * granted, so the level is exactly the preset.
     *
     * @param list<int> $serverIds
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    public function grant(User $admin, array $serverIds, string $user, string $host, string $database, string $level): array
    {
        [$user, $host] = [trim($user), trim($host)];
        $target = self::target($database);
        $privileges = $this->privileges($level);

        return $this->onEach($admin, $serverIds, 'grant', $user, $host, self::LEVELS[$level][0] . ' on ' . $this->databaseLabel($database), function (PDO $pdo) use ($user, $host, $target, $privileges) {
            $account = $this->quoted($pdo, $user, $host);
            $this->revokeAll($pdo, $target, $account);
            $pdo->exec("GRANT $privileges ON $target TO $account");

            return 'Access set.';
        });
    }

    /**
     * Take away the account's access to one database (or all).
     *
     * @param list<int> $serverIds
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    public function revoke(User $admin, array $serverIds, string $user, string $host, string $database): array
    {
        [$user, $host] = [trim($user), trim($host)];
        $target = self::target($database);

        return $this->onEach($admin, $serverIds, 'revoke', $user, $host, 'on ' . $this->databaseLabel($database), function (PDO $pdo) use ($user, $host, $target) {
            return $this->revokeAll($pdo, $target, $this->quoted($pdo, $user, $host)) ? 'Access removed.' : 'It had no access there.';
        });
    }

    /**
     * Drop the account. Its tracked account in Accounts goes too, once no account of that name is left on
     * the server (and the server doesn't monitor with it).
     *
     * @param list<int> $serverIds
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    public function drop(User $admin, array $serverIds, string $user, string $host): array
    {
        [$user, $host] = [trim($user), trim($host)];
        return $this->onEach($admin, $serverIds, 'drop', $user, $host, null, function (PDO $pdo, Server $server) use ($user, $host, $admin) {
            $pdo->exec('DROP USER ' . $this->quoted($pdo, $user, $host));
            $left = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
            $left->execute([$user]);
            $untracked = (int) $left->fetchColumn() === 0 && $this->untrack($admin, $server, $user);

            return 'Dropped' . ($untracked ? '; removed from Accounts.' : '.');
        });
    }

    /**
     * A password for a new account: 24 characters that need no escaping anywhere.
     */
    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_';
        $password = '';

        for ($i = 0; $i < 24; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    public static function name(string $user, string $host): string
    {
        return "'$user'@'$host'";
    }

    /**
     * Run one change on each server: checked first (admin, names, eligibility, never the monitoring login or
     * a system account), then made, reported and logged per server.
     *
     * @param list<int> $serverIds
     * @param callable(PDO, Server): string $change
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException|AuthorizationException
     */
    private function onEach(User $admin, array $serverIds, string $action, string $user, string $host, ?string $detail, callable $change): array
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage database accounts.');
        }

        $user = trim($user);
        $host = trim($host);
        $this->checkName($user, $host);
        $servers = array_values(array_filter($this->servers(), fn (Server $s) => in_array($s->id, $serverIds, true)));

        if ($servers === []) {
            throw new DomainException('Choose at least one server.');
        }

        $results = [];

        foreach ($servers as $server) {
            try {
                if (!$this->eligibility($server)['ok']) {
                    throw new DomainException($this->eligibility($server)['reason']);
                }

                if (strcasecmp($user, (string) $server->mysql_username) === 0) {
                    throw new DomainException("That's the account sys monitors {$server->name} with; change it on the server form instead.");
                }

                $message = $change($this->mysql->connect($server), $server);
                $results[] = ['server' => $server, 'ok' => true, 'message' => $message];
            } catch (DomainException|ServerConnectionException|PDOException $e) {
                $results[] = ['server' => $server, 'ok' => false, 'message' => $e->getMessage()];
            }

            $last = $results[array_key_last($results)];
            DbUserChange::query()->create([
                'user_id' => $admin->id, 'server_id' => $server->id, 'action' => $action, 'account' => self::name($user, $host),
                'detail' => $detail, 'ok' => $last['ok'], 'message' => mb_substr($last['message'], 0, 2000), 'created_at' => Carbon::instance($this->clock->now()),
            ]);
        }

        return $results;
    }

    /**
     * @throws DomainException
     */
    private function checkName(string $user, string $host): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user) !== 1) {
            throw new DomainException('The username may have letters, digits, "_", "." and "-", up to 80 characters.');
        }

        if (in_array(strtolower($user), self::PROTECTED_USERS, true)) {
            throw new DomainException("$user is a system account; the account manager leaves it alone.");
        }

        // '%', 'localhost', an address or pattern (10.0.0.%, 2001:db8::%), a netmask (10.0.0.0/255.255.255.0) or a hostname.
        if (preg_match('/^[A-Za-z0-9.%_:\/-]{1,255}$/', $host) !== 1) {
            throw new DomainException('The host may be %, localhost, an address or pattern like 10.0.0.%, or a hostname.');
        }
    }

    /**
     * @throws DomainException
     */
    private function checkPassword(string $password, string $user): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new DomainException('The password needs at least ' . self::MIN_PASSWORD . ' characters (or generate one).');
        }

        if ($user !== '' && stripos($password, $user) !== false) {
            throw new DomainException('The password mustn\'t contain the username.');
        }
    }

    /**
     * @throws DomainException
     */
    private function privileges(string $level): string
    {
        if (!isset(self::LEVELS[$level])) {
            throw new DomainException('Choose the access: read only, read/write or full.');
        }

        return self::LEVELS[$level][1];
    }

    private function levelLabelFor(string $privileges): string
    {
        foreach (self::LEVELS as [$label, $list]) {
            if ($list === $privileges) {
                return $label;
            }
        }

        return $privileges;
    }

    /**
     * What a grant is on: *.* for everything ("*" or "*.*"), `db`.* for a database ("db" or "db.*"), or
     * `db`.`table` for one table or view ("db.table").
     *
     * @throws DomainException
     */
    private static function target(string $database): string
    {
        $database = trim($database);

        if ($database === '*' || $database === '*.*') {
            return '*.*';
        }

        [$schema, $table] = array_pad(explode('.', $database, 2), 2, '*');
        $name = '/^[A-Za-z0-9_$-]{1,64}$/';
        // A database name may be a pattern, as MariaDB writes it: shop\_% (grants on every shop_... database).
        $pattern = '/^[A-Za-z0-9_$%\\\\-]{1,64}$/';

        if (preg_match($pattern, $schema) !== 1 || ($table !== '*' && (preg_match($name, $table) !== 1 || preg_match($name, $schema) !== 1))) {
            throw new DomainException('Name the database (letters, digits, "_", "$", "-"), a table in it as database.table, or * for all of them.');
        }

        return $table === '*' ? "`$schema`.*" : "`$schema`.`$table`";
    }

    private function databaseLabel(string $database): string
    {
        return trim($database) === '*' ? 'every database' : trim($database);
    }

    private function quoted(PDO $pdo, string $user, string $host): string
    {
        return $pdo->quote($user) . '@' . $pdo->quote($host);
    }

    /**
     * REVOKE ALL on the target; false when there was nothing to revoke.
     */
    private function revokeAll(PDO $pdo, string $target, string $account): bool
    {
        try {
            $pdo->exec("REVOKE ALL PRIVILEGES ON $target FROM $account");

            return true;
        } catch (PDOException $e) {
            // 1141: no such grant (global or database); 1147: none on that table.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1141, 1147], true)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Record the login in Accounts (the server's local database account of that name) with its password, or none for a socket login.
     */
    private function track(User $admin, Server $server, string $user, string $host, ?string $password): void
    {
        $accounts = $this->accounts ??= new AccountService();
        $account = Account::query()->where('type', Account::TYPE_LOCAL)->where('server_id', $server->id)->where('username', $user)
            ->get()->first(fn (Account $a) => $a->serviceName() === Account::SERVICE_MYSQL);

        if (!$account instanceof Account) {
            $account = new Account([
                'username' => $user, 'type' => Account::TYPE_LOCAL, 'server_id' => $server->id, 'service' => Account::SERVICE_MYSQL,
                'origin' => Account::ORIGIN_MANUAL, 'origin_user_id' => $admin->id, 'origin_server_id' => $server->id,
                'origin_detail' => self::name($user, $host) . ', created by the database account manager',
            ]);
        }

        if ($password === null) {
            // A socket login: nothing to store; say how it signs in.
            $account->notes ??= 'Signs in by socket (unix_socket / auth_socket): the system user of the same name, over the local socket; no password.';
            $account->save();

            return;
        }

        $accounts->storePassword($account, $password, Carbon::instance($this->clock->now()));
    }

    /**
     * Remove the server's tracked account of that name, unless the server monitors with it.
     */
    private function untrack(User $admin, Server $server, string $user): bool
    {
        $account = Account::query()->where('type', Account::TYPE_LOCAL)->where('server_id', $server->id)->where('username', $user)
            ->get()->first(fn (Account $a) => $a->serviceName() === Account::SERVICE_MYSQL);

        if (!$account instanceof Account || $server->mysql_account_id === $account->id) {
            return false;
        }

        try {
            ($this->accounts ??= new AccountService())->delete($admin, $account);

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
