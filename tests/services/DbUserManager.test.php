<?php

use App\Exceptions\AuthorizationException;
use App\Models\Account;
use App\Models\DbUserChange;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\DbUserManagerService;
use App\Services\MysqlService;
use App\Services\ServerService;

// A MariaDB stand-in: SQLite with a mysql.user table; account statements are recorded and applied to it,
// SHOW GRANTS answers from $grants.
class DbUserFakePdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> the monitoring account's grants */
    public array $grants = ["GRANT ALL PRIVILEGES ON *.* TO `monitor`@`%` WITH GRANT OPTION"];

    /** @var array<string, list<string>> 'user'@'host' => grants */
    public array $userGrants = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        parent::exec("ATTACH ':memory:' AS mysql");
        parent::exec('CREATE TABLE mysql.user (User TEXT, Host TEXT, is_role TEXT DEFAULT \'N\')');
        parent::exec("INSERT INTO mysql.user VALUES ('root', 'localhost', 'N'), ('monitor', '%', 'N'), ('app', '%', 'N'), ('app', 'localhost', 'N'), ('reporting', '', 'Y')");
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        if (preg_match("/^CREATE USER '([^']*)'@'([^']*)'/", $statement, $m) === 1) {
            parent::exec("INSERT INTO mysql.user (User, Host) VALUES ('$m[1]', '$m[2]')");
            $this->userGrants["'$m[1]'@'$m[2]'"] = ["GRANT USAGE ON *.* TO `$m[1]`@`$m[2]`"];
        } elseif (preg_match("/^DROP USER '([^']*)'@'([^']*)'/", $statement, $m) === 1) {
            parent::exec("DELETE FROM mysql.user WHERE User = '$m[1]' AND Host = '$m[2]'");
        } elseif (preg_match('/^REVOKE ALL PRIVILEGES ON (\S+) FROM (\S+)$/', $statement, $m) === 1) {
            $before = count($this->userGrants[$m[2]] ?? []);
            $this->userGrants[$m[2]] = array_values(array_filter($this->userGrants[$m[2]] ?? [], fn ($g) => !str_contains($g, " ON $m[1] ")));

            if ($before === count($this->userGrants[$m[2]])) {
                $e = new PDOException('There is no such grant defined');
                $e->errorInfo = ['42000', 1141, 'There is no such grant'];

                throw $e;
            }
        } elseif (preg_match('/^GRANT (.+) ON (\S+) TO (\S+)$/', $statement, $m) === 1) {
            $this->userGrants[$m[3]][] = "GRANT $m[1] ON $m[2] TO $m[3]";
        }

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_starts_with($query, 'SHOW GRANTS FOR CURRENT_USER()')) {
            return parent::query('SELECT ' . implode(' UNION ALL SELECT ', array_map(fn ($g) => $this->quote($g), $this->grants)));
        }

        return parent::query($query);
    }
}

class DbUserFakeMysql extends MysqlService
{
    /** @var array<string, DbUserFakePdo> */
    public array $servers = [];

    public function __construct()
    {
    }

    public function connect(Server $server, ?string $database = null): PDO
    {
        return $this->servers[$server->name];
    }
}

beforeEach(function () {
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->dev = User::query()->create(['username' => 'dev', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    $servers = new ServerService($this->cipher);
    $this->mysql = new DbUserFakeMysql();

    foreach (['alpha', 'beta', 'gamma'] as $name) {
        $server = $servers->create($this->admin, ['name' => $name, 'hostname' => "$name.example.com", 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'monitor', 'mysql_password' => 'monitor-pw', 'mysql_port' => 3306, 'mysql_tls' => 'off']);
        $this->mysql->servers[$name] = new DbUserFakePdo();
        $this->ids[$name] = $server->id;
        $this->servers[$name] = $server;
    }

    // gamma's monitoring account can only read.
    $this->mysql->servers['gamma']->grants = ['GRANT SELECT, PROCESS ON *.* TO `monitor`@`%`'];
    $this->accounts = new AccountService($this->cipher, clock: $this->clock);
    $this->manager = new DbUserManagerService($this->mysql, $this->accounts, $this->clock);
    $this->all = array_values($this->ids);
});

test('only a monitoring account that can create users and grant privileges on *.* qualifies', function (array $grants, bool $expected) {
    expect(DbUserManagerService::canManage($grants))->toBe($expected);
})->with([
    [['GRANT ALL PRIVILEGES ON *.* TO `m`@`%` WITH GRANT OPTION'], true],
    [['GRANT SELECT, CREATE USER, RELOAD ON *.* TO `m`@`localhost` IDENTIFIED BY PASSWORD \'*x\' WITH GRANT OPTION'], true],
    [['GRANT ALL PRIVILEGES ON *.* TO `m`@`%`'], false],
    [['GRANT SELECT, PROCESS ON *.* TO `m`@`%` WITH GRANT OPTION'], false],
    [['GRANT ALL PRIVILEGES ON `shop`.* TO `m`@`%` WITH GRANT OPTION'], false],
    [[], false],
]);

test('eligible servers are found; accounts are listed across them, roles left out', function () {
    expect($this->manager->eligibility($this->servers['alpha'])['ok'])->toBeTrue()
        ->and($this->manager->eligibility($this->servers['gamma']))->toMatchArray(['ok' => false])
        ->and($this->manager->eligibility($this->servers['gamma'])['reason'])->toContain('CREATE USER (or ALL) on *.* WITH GRANT OPTION');

    $accounts = $this->manager->accounts(array_values($this->servers));
    expect(array_keys($accounts))->toBe(["'app'@'%'", "'app'@'localhost'", "'monitor'@'%'", "'root'@'localhost'"])
        ->and(array_keys($accounts["'app'@'%'"]['on']))->toBe([$this->ids['alpha'], $this->ids['beta']]);
});

test('an account is created on several servers with a preset, tracked in Accounts with its password; ineligible servers refuse', function () {
    $results = $this->manager->create($this->admin, $this->all, 'shop_ro', '10.0.0.%', 'a long secret password', 'shop', 'read', true);

    expect(array_map(fn ($r) => [$r['server']->name, $r['ok']], $results))->toBe([['alpha', true], ['beta', true], ['gamma', false]])
        ->and($results[0]['message'])->toBe('Created, with read only access; tracked in Accounts.')
        ->and($results[2]['message'])->toContain("can't create users")
        ->and($this->mysql->servers['alpha']->statements)->toBe(["CREATE USER 'shop_ro'@'10.0.0.%' IDENTIFIED BY 'a long secret password'", "GRANT SELECT ON `shop`.* TO 'shop_ro'@'10.0.0.%'"])
        ->and($this->mysql->servers['gamma']->statements)->toBe([]);

    $tracked = Account::query()->where('username', 'shop_ro')->orderBy('server_id')->get();
    expect($tracked)->toHaveCount(2)
        ->and($tracked[0]->type)->toBe(Account::TYPE_LOCAL)
        ->and($tracked[0]->serviceName())->toBe(Account::SERVICE_MYSQL)
        ->and($this->accounts->password($tracked[0]))->toBe('a long secret password')
        ->and($tracked[0]->origin_detail)->toContain("'shop_ro'@'10.0.0.%'")
        // Logged per server, never the password.
        ->and(DbUserChange::query()->count())->toBe(3)
        ->and(json_encode(DbUserChange::query()->get()->toArray()))->not->toContain('a long secret password');

    // Already there: refused on that server.
    $again = $this->manager->create($this->admin, [$this->ids['alpha']], 'shop_ro', '10.0.0.%', 'another long password', '*', 'all', false);
    expect($again[0]['ok'])->toBeFalse()->and($again[0]['message'])->toBe('It already exists on this server.');
});

test('access is set exactly (revoke, then grant), removed, and the password changed', function () {
    $this->manager->create($this->admin, [$this->ids['alpha']], 'shop_rw', '%', 'a long secret password', 'shop', 'read', true);
    $pdo = $this->mysql->servers['alpha'];
    $pdo->statements = [];

    $set = $this->manager->grant($this->admin, [$this->ids['alpha']], 'shop_rw', '%', 'shop', 'write');
    $removed = $this->manager->revoke($this->admin, [$this->ids['alpha']], 'shop_rw', '%', 'reports');
    $password = $this->manager->setPassword($this->admin, [$this->ids['alpha']], 'shop_rw', '%', 'a new long password', true);

    expect($set[0]['message'])->toBe('Access set.')
        ->and($removed[0]['message'])->toBe('It had no access there.')
        ->and($password[0]['message'])->toBe('Password changed; tracked in Accounts.')
        ->and($pdo->statements)->toBe([
            "REVOKE ALL PRIVILEGES ON `shop`.* FROM 'shop_rw'@'%'",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO 'shop_rw'@'%'",
            "REVOKE ALL PRIVILEGES ON `reports`.* FROM 'shop_rw'@'%'",
            "ALTER USER 'shop_rw'@'%' IDENTIFIED BY 'a new long password'",
        ])
        ->and($this->accounts->password(Account::query()->where('username', 'shop_rw')->first()))->toBe('a new long password');
});

test('access can be given on one table as database.table, and on everything as * or *.*', function () {
    $this->manager->create($this->admin, [$this->ids['alpha']], 'report', '%', 'a long secret password', 'shop.orders', 'read', false);
    $pdo = $this->mysql->servers['alpha'];
    $pdo->statements = [];

    $this->manager->grant($this->admin, [$this->ids['alpha']], 'report', '%', 'shop.*', 'write');
    $this->manager->revoke($this->admin, [$this->ids['alpha']], 'report', '%', 'shop.orders');
    $this->manager->grant($this->admin, [$this->ids['alpha']], 'report', '%', '*.*', 'read');

    expect($pdo->statements)->toBe([
        "REVOKE ALL PRIVILEGES ON `shop`.* FROM 'report'@'%'",
        "GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO 'report'@'%'",
        "REVOKE ALL PRIVILEGES ON `shop`.`orders` FROM 'report'@'%'",
        "REVOKE ALL PRIVILEGES ON *.* FROM 'report'@'%'",
        "GRANT SELECT ON *.* TO 'report'@'%'",
    ])
        ->and(fn () => $this->manager->grant($this->admin, [$this->ids['alpha']], 'report', '%', 'shop.or ders', 'read'))->toThrow(DomainException::class, 'database.table')
        ->and(fn () => $this->manager->grant($this->admin, [$this->ids['alpha']], 'report', '%', 'shop.a.b', 'read'))->toThrow(DomainException::class, 'database.table');
});

test('the database fields\' suggestions are for admins only', function () {
    expect(fn () => $this->manager->names($this->dev, [$this->ids['alpha']]))->toThrow(AuthorizationException::class);
});

test('dropping removes the tracked account once no account of that name is left on the server', function () {
    $this->manager->create($this->admin, [$this->ids['alpha']], 'temp', '%', 'a long secret password', '*', 'read', true);
    $this->manager->create($this->admin, [$this->ids['alpha']], 'temp', 'localhost', 'a long secret password', '*', 'read', false);

    $first = $this->manager->drop($this->admin, [$this->ids['alpha']], 'temp', '%');
    expect($first[0]['message'])->toBe('Dropped.')
        ->and(Account::query()->where('username', 'temp')->count())->toBe(1);

    $last = $this->manager->drop($this->admin, [$this->ids['alpha']], 'temp', 'localhost');
    expect($last[0]['message'])->toBe('Dropped; removed from Accounts.')
        ->and(Account::query()->where('username', 'temp')->count())->toBe(0);
});

test('the monitoring login, system accounts, bad names and non-admins are refused', function () {
    expect($this->manager->drop($this->admin, [$this->ids['alpha']], 'monitor', '%')[0]['message'])->toContain('the account sys monitors alpha with')
        ->and(fn () => $this->manager->drop($this->admin, [$this->ids['alpha']], 'root', 'localhost'))->toThrow(DomainException::class, 'system account')
        ->and(fn () => $this->manager->drop($this->admin, [$this->ids['alpha']], "app'; DROP", '%'))->toThrow(DomainException::class, 'The username may have')
        ->and(fn () => $this->manager->drop($this->admin, [$this->ids['alpha']], 'app', "%' OR '1"))->toThrow(DomainException::class, 'The host may be')
        ->and(fn () => $this->manager->grant($this->admin, [$this->ids['alpha']], 'app', '%', 'shop`; DROP', 'read'))->toThrow(DomainException::class, 'Name the database')
        ->and(fn () => $this->manager->grant($this->admin, [$this->ids['alpha']], 'app', '%', 'shop', 'root'))->toThrow(DomainException::class, 'Choose the access')
        ->and(fn () => $this->manager->create($this->admin, [$this->ids['alpha']], 'app2', '%', 'short', 'shop', 'read', false))->toThrow(DomainException::class, 'at least 12')
        ->and(fn () => $this->manager->create($this->admin, [$this->ids['alpha']], 'app2', '%', 'my app2 password', 'shop', 'read', false))->toThrow(DomainException::class, 'mustn\'t contain the username')
        ->and(fn () => $this->manager->drop($this->dev, [$this->ids['alpha']], 'app', '%'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->manager->drop($this->admin, [], 'app', '%'))->toThrow(DomainException::class, 'Choose at least one server')
        ->and($this->mysql->servers['alpha']->statements)->toBe([]);
});

test('generated passwords are long and plain', function () {
    $password = DbUserManagerService::generatePassword();

    expect(strlen($password))->toBe(24)
        ->and($password)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(DbUserManagerService::generatePassword())->not->toBe($password);
});

test('a grant line says what its removal would revoke, when it can be removed here', function (string $grant, ?string $target) {
    expect(DbUserManagerService::grantTarget($grant))->toBe($target);
})->with([
    ["GRANT USAGE ON *.* TO `app`@`%` IDENTIFIED BY PASSWORD '*AB'", null],
    ['GRANT SELECT, PROCESS ON *.* TO `app`@`%`', '*'],
    ['GRANT SELECT, INSERT ON `shop`.* TO `app`@`%`', 'shop'],
    ['GRANT SELECT, UPDATE (`note`) ON `shop`.`orders` TO `app`@`%`', 'shop.orders'],
    ['GRANT SELECT ON `shop\_%`.* TO `app`@`%`', 'shop\_%'],
    ['GRANT `reader` TO `app`@`%`', null],
    ["GRANT PROXY ON ''@'%' TO 'app'@'%'", null],
    ['GRANT EXECUTE ON PROCEDURE `shop`.`p` TO `app`@`%`', null],
    ['GRANT SELECT ON `odd``name`.* TO `app`@`%`', null],
]);
