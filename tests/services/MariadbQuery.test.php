<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbQuery;
use App\Models\QueryAccount;
use App\Models\Server;
use App\Models\User;
use App\Services\MariadbQueryService;
use App\Services\MysqlService;
use App\Services\QueryAccountService;
use App\Services\ServerService;

// A login let in by socket identity: over the local socket, its account IDENTIFIED VIA unix_socket.
class QueryToolSocketPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_CONNECTION_STATUS ? 'Localhost via UNIX socket' : parent::getAttribute($attribute);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return str_starts_with($query, 'SHOW GRANTS FOR CURRENT_USER()')
            ? parent::query("SELECT 'GRANT USAGE ON *.* TO `www-data`@`localhost` IDENTIFIED VIA unix_socket'")
            : parent::query($query, ...array_filter([$fetchMode], fn ($m) => $m !== null), ...$fetchModeArgs);
    }
}

// Each "server" is an in-memory SQLite database. Logins: $users (username => password) on every server,
// except the pairs in $refuse (server name => usernames it refuses). Stored-account logins are recorded.
class QueryToolFakeMysql extends MysqlService
{
    /** @var array<string, PDO> */
    public array $databases = [];

    /** @var array<string, string> */
    public array $users = ['dev' => 'secret', 'ro' => 'readonly'];

    /** @var array<string, list<string>> */
    public array $refuse = [];

    /** @var list<string> */
    public array $connections = [];

    public function __construct()
    {
    }

    public function connectAs(Server $server, string $username, string $password, ?string $database = null): PDO
    {
        $this->connections[] = "{$server->name} as $username";

        // A socket login: any password does (as with unix_socket over the socket).
        if ($username === 'www-data') {
            return new QueryToolSocketPdo();
        }

        if ($username === 'sockonly') {
            $e = new PDOException("SQLSTATE[HY000] [1698] Access denied for user 'sockonly'@'localhost'");
            $e->errorInfo = ['HY000', 1698, 'Access denied'];

            throw new ServerConnectionException("MySQL connection to {$server->name} failed: {$e->getMessage()}", 0, $e);
        }

        if (($this->users[$username] ?? null) !== $password || in_array($username, $this->refuse[$server->name] ?? [], true)) {
            $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user '$username'@'sys' (using password: YES)");
            $e->errorInfo = ['HY000', 1045, 'Access denied'];

            throw new ServerConnectionException("MySQL connection to {$server->name} failed: {$e->getMessage()}", 0, $e);
        }

        return $this->databases[$server->name];
    }

    public function connect(Server $server, ?string $database = null): PDO
    {
        $this->connections[] = "{$server->name} as stored";

        return $this->databases[$server->name];
    }
}

beforeEach(function () {
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->dev = User::query()->create(['username' => 'dev', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    $this->other = User::query()->create(['username' => 'other', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    $servers = new ServerService($this->cipher);
    $this->mysql = new QueryToolFakeMysql();

    foreach (['alpha', 'beta', 'gamma'] as $i => $name) {
        $server = $servers->create($this->admin, ['name' => $name, 'hostname' => "$name.example.com", 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'monitor', 'mysql_password' => 'monitor-pw', 'mysql_port' => 3306, 'mysql_tls' => 'off']);
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE orders (id INTEGER, total REAL, note TEXT)');

        for ($n = 1; $n <= $i + 2; $n++) {
            $pdo->exec("INSERT INTO orders VALUES ($n, " . ($n * 10) . ', ' . ($n === 1 ? 'NULL' : "'order $n'") . ')');
        }

        $this->mysql->databases[$name] = $pdo;
        $this->ids[$name] = $server->id;
    }

    $this->accounts = new QueryAccountService($this->mysql, $this->cipher, $this->clock);
    $this->query = new MariadbQueryService($this->mysql, $this->clock, $this->accounts);
    // An account of $user's that works everywhere it's for.
    $this->account = function (User $user, string $label, string $username, string $password, array $servers) {
        $saved = $this->accounts->save($user, null, ['label' => $label, 'username' => $username, 'password' => $password, 'servers' => array_map(fn ($n) => $this->ids[$n], $servers)]);

        return $saved['account'];
    };
    $this->mysql->connections = [];
});

test('plain reads are told apart from statements that may change data', function (string $sql, bool $read) {
    expect(MariadbQueryService::isRead($sql))->toBe($read);
})->with([
    ['SELECT 1', true], ["  -- note\n/* x */ show databases", true], ['DESCRIBE t', true], ['WITH a AS (SELECT 1) SELECT * FROM a', true],
    ['UPDATE t SET a = 1', false], ['delete from t', false], ['DROP TABLE t', false], ['/* SELECT */ INSERT INTO t VALUES (1)', false], ['', false],
]);

test('an account is saved only when its login works on every server it\'s for; the password is kept encrypted', function () {
    $saved = $this->accounts->save($this->dev, null, ['label' => 'Dev', 'username' => 'dev', 'password' => 'secret', 'servers' => [$this->ids['alpha'], $this->ids['beta']]]);

    expect($saved['account'])->toBeInstanceOf(QueryAccount::class)
        ->and(array_column($saved['results'], 'ok'))->toBe([true, true])
        ->and($saved['account']->servers)->toBe([$this->ids['alpha'], $this->ids['beta']])
        ->and($saved['account']->tested_at)->not->toBeNull()
        ->and(QueryAccount::query()->value('password'))->not->toContain('secret')
        ->and($this->accounts->password($saved['account']->fresh()))->toBe('secret');

    // gamma refuses dev: not saved, and gamma is named.
    $this->mysql->refuse['gamma'] = ['dev'];
    $refused = $this->accounts->save($this->dev, null, ['label' => 'Dev 2', 'username' => 'dev', 'password' => 'secret', 'servers' => array_values($this->ids)]);

    expect($refused['account'])->toBeNull()
        ->and(array_map(fn ($r) => [$r['server']->name, $r['ok']], $refused['results']))->toBe([['alpha', true], ['beta', true], ['gamma', false]])
        ->and(QueryAccount::query()->count())->toBe(1);

    // A wrong password refused by the first server: the others aren't tried.
    $this->mysql->connections = [];
    $wrong = $this->accounts->save($this->dev, null, ['label' => 'Typo', 'username' => 'dev', 'password' => 'secre', 'servers' => array_values($this->ids)]);
    expect($wrong['account'])->toBeNull()
        ->and($this->mysql->connections)->toBe(['alpha as dev'])
        ->and($wrong['results'][1]['message'])->toBe('Not tried: the first server refused the login.');
});

test('editing: a blank password keeps the stored one, and the change must still work everywhere', function () {
    $account = ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha']);

    $saved = $this->accounts->save($this->dev, $account, ['label' => 'Dev everywhere', 'username' => 'dev', 'password' => '', 'servers' => array_values($this->ids)]);
    expect($saved['account']->label)->toBe('Dev everywhere')
        ->and(count($saved['account']->servers))->toBe(3)
        ->and($this->accounts->password($saved['account']))->toBe('secret');

    // A new password that doesn't work: nothing changes.
    $failed = $this->accounts->save($this->dev, $account->fresh(), ['label' => 'Renamed', 'username' => 'dev', 'password' => 'nope', 'servers' => [$this->ids['alpha']]]);
    expect($failed['account'])->toBeNull()
        ->and($account->fresh()->label)->toBe('Dev everywhere')
        ->and($this->accounts->password($account->fresh()))->toBe('secret');
});

test('accounts are private: another user can\'t see, use, change, test or delete them', function () {
    $account = ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha']);

    expect($this->accounts->forUser($this->other))->toBe([])
        ->and(fn () => $this->accounts->find($this->other, $account->id))->toThrow(DomainException::class, 'No such account')
        ->and(fn () => $this->accounts->save($this->other, $account, ['label' => 'x', 'username' => 'dev', 'password' => '', 'servers' => [$this->ids['alpha']]]))->toThrow(DomainException::class, 'No such account')
        ->and(fn () => $this->accounts->test($this->other, $account))->toThrow(DomainException::class, 'No such account')
        ->and(fn () => $this->accounts->delete($this->other, $account))->toThrow(DomainException::class, 'No such account')
        ->and(fn () => $this->query->run($this->other, [$this->ids['alpha'] => $account->id], null, 'SELECT 1'))->toThrow(DomainException::class, 'Choose one of your accounts for alpha')
        ->and(QueryAccount::query()->count())->toBe(1);
});

test('each server runs with its chosen login; results are collated with the server first', function () {
    $dev = ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha', 'beta']);
    $ro = ($this->account)($this->dev, 'Read only', 'ro', 'readonly', ['gamma']);
    $this->mysql->connections = [];

    $result = $this->query->run($this->dev, [$this->ids['alpha'] => $dev->id, $this->ids['beta'] => $dev->id, $this->ids['gamma'] => $ro->id], null, "SELECT id, note FROM orders ORDER BY id;\n");

    expect($result['columns'])->toBe(['id', 'note'])
        ->and(array_column($result['rows'], 'server'))->toBe(['alpha', 'alpha', 'beta', 'beta', 'beta', 'gamma', 'gamma', 'gamma', 'gamma'])
        ->and($result['rows'][0]['values'])->toBe(['id' => '1', 'note' => null])
        ->and(array_map(fn ($s) => [$s['name'], $s['login'], $s['message']], $result['servers']))->toBe([['alpha', 'Dev', '2 rows'], ['beta', 'Dev', '3 rows'], ['gamma', 'Read only', '4 rows']])
        ->and($this->mysql->connections)->toBe(['alpha as dev', 'beta as dev', 'gamma as ro'])
        ->and($dev->fresh()->last_used_at)->not->toBeNull()
        // Logged without the password.
        ->and(MariadbQuery::query()->latest('id')->first()->db_user)->toBe('Dev, Read only')
        ->and(json_encode(MariadbQuery::query()->get()->toArray()))->not->toContain('secret')
        // An account that isn't for a server can't be used on it.
        ->and(fn () => $this->query->run($this->dev, [$this->ids['gamma'] => $dev->id], null, 'SELECT 1'))->toThrow(DomainException::class, 'Choose one of your accounts for gamma');
});

test('a login refused by the first server it meets isn\'t tried on its other servers; other logins go on', function () {
    $dev = ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha', 'beta']);
    $ro = ($this->account)($this->dev, 'Read only', 'ro', 'readonly', ['gamma']);
    $this->mysql->users['dev'] = 'changed on the servers';
    $this->mysql->connections = [];

    $result = $this->query->run($this->dev, [$this->ids['alpha'] => $dev->id, $this->ids['beta'] => $dev->id, $this->ids['gamma'] => $ro->id], null, 'SELECT 1 AS one');

    expect($result['auth_failed'])->toBeTrue()
        ->and($this->mysql->connections)->toBe(['alpha as dev', 'gamma as ro'])
        ->and($result['servers'][1]['message'])->toBe('Not tried: alpha refused this login first.')
        ->and($result['servers'][2]['ok'])->toBeTrue();
});

test('repeated refusals, from queries and account checks alike, make the user wait', function () {
    for ($i = 0; $i < MariadbQueryService::AUTH_FAILURES; $i++) {
        $this->accounts->save($this->dev, null, ['label' => 'Typo', 'username' => 'dev', 'password' => 'wrong', 'servers' => [$this->ids['alpha']]]);
    }

    expect(fn () => $this->accounts->save($this->dev, null, ['label' => 'Dev', 'username' => 'dev', 'password' => 'secret', 'servers' => [$this->ids['alpha']]]))->toThrow(DomainException::class, 'Too many refused database logins');

    $this->clock->advance(MariadbQueryService::AUTH_WINDOW_MINUTES * 60 + 1);
    expect(($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha']))->toBeInstanceOf(QueryAccount::class);
});

test('an admin can clear their own refused-login wait; others can\'t', function () {
    foreach ([$this->dev, $this->admin] as $user) {
        for ($i = 0; $i < MariadbQueryService::AUTH_FAILURES; $i++) {
            $this->accounts->save($user, null, ['label' => 'Typo', 'username' => 'dev', 'password' => 'wrong', 'servers' => [$this->ids['alpha']]]);
        }
    }

    expect($this->query->throttled($this->admin))->toBeTrue()
        ->and(fn () => $this->query->clearThrottle($this->dev))->toThrow(AuthorizationException::class)
        ->and($this->query->throttled($this->dev))->toBeTrue()
        ->and($this->query->clearThrottle($this->admin))->toBe(MariadbQueryService::AUTH_FAILURES)
        ->and($this->query->throttled($this->admin))->toBeFalse()
        ->and($this->query->throttled($this->dev))->toBeTrue()
        ->and(MariadbQuery::query()->where('user_id', $this->admin->id)->latest('id')->value('statement'))->toBe('(refused-login wait cleared)');
    expect(($this->account)($this->admin, 'Dev', 'dev', 'secret', ['alpha']))->toBeInstanceOf(QueryAccount::class);
});

test('only admins can use a server\'s stored account', function () {
    expect(fn () => $this->query->run($this->dev, [$this->ids['alpha'] => 'stored'], null, 'SELECT 1'))->toThrow(AuthorizationException::class);

    $result = $this->query->run($this->admin, array_fill_keys(array_values($this->ids), 'stored'), null, 'SELECT COUNT(*) AS n FROM orders');
    expect(array_column($result['rows'], 'values'))->toBe([['n' => '2'], ['n' => '3'], ['n' => '4']])
        ->and($this->mysql->connections)->toBe(['alpha as stored', 'beta as stored', 'gamma as stored'])
        ->and(MariadbQuery::query()->latest('id')->value('db_user'))->toBe('stored account');
});

test('a statement that may change data runs only when confirmed; rows per server are limited; values are shown safely', function () {
    $dev = ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha', 'beta', 'gamma']);
    $plan = array_fill_keys(array_values($this->ids), $dev->id);

    expect(fn () => $this->query->run($this->dev, $plan, null, 'DELETE FROM orders WHERE id = 1'))->toThrow(DomainException::class, 'may change data on 3 servers');

    $result = $this->query->run($this->dev, $plan, null, 'DELETE FROM orders WHERE id = 1', confirmedWrites: true);
    expect(array_column($result['servers'], 'message'))->toBe(['1 row affected', '1 row affected', '1 row affected']);

    $limited = $this->query->run($this->dev, [$this->ids['gamma'] => $dev->id], null, 'SELECT * FROM orders', 2);
    expect(count($limited['rows']))->toBe(2)->and($limited['servers'][0]['message'])->toBe('2 rows (the first 2)');

    $values = $this->query->run($this->dev, [$this->ids['alpha'] => $dev->id], null, "SELECT X'00FF10' AS bin, '" . str_repeat('x', 2500) . "' AS long");
    expect($values['rows'][0]['values']['bin'])->toBe('0x00ff10')
        ->and(mb_strlen($values['rows'][0]['values']['long']))->toBe(MariadbQueryService::MAX_CELL + 1);
});

test('input is checked before anything runs', function (array $args, string $message) {
    ($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha']);
    $this->mysql->connections = [];

    expect(fn () => $this->query->run($this->dev, ...$args))->toThrow(DomainException::class, $message);
    expect($this->mysql->connections)->toBe([]);
})->with([
    [[[1 => 1], null, '  ;  '], 'Type a statement'],
    [[[], null, 'SELECT 1'], 'Choose at least one server'],
    [[[1 => 1], 'shop; DROP', 'SELECT 1'], "isn't a database name"],
]);

test('account input is checked', function (array $input, string $message) {
    expect(fn () => $this->accounts->save($this->dev, null, $input + ['label' => 'A', 'username' => 'dev', 'password' => 'secret', 'servers' => [1]]))->toThrow(DomainException::class, $message);
})->with([
    [['label' => ''], 'Give the account a name'],
    [['username' => ''], 'Type the database username'],
    [['servers' => []], 'Choose the servers'],
    [['servers' => [999]], 'Choose the servers'],
]);

test('an account that signs in without a password (unix_socket) is explained, and doesn\'t count as a guess', function () {
    for ($i = 0; $i < MariadbQueryService::AUTH_FAILURES + 1; $i++) {
        $saved = $this->accounts->save($this->dev, null, ['label' => 'Me', 'username' => 'sockonly', 'password' => 'anything', 'servers' => array_values($this->ids)]);
    }

    expect($saved['account'])->toBeNull()
        ->and($saved['results'][0]['message'])->toContain('[1698]')->toContain("doesn't sign in with a password")->toContain('IDENTIFIED VIA unix_socket OR mysql_native_password')
        ->and($saved['results'][1]['message'])->toBe('Not tried: the first server refused the login.')
        // Six refusals, none counted: a real account can still be added.
        ->and(MariadbQuery::query()->where('auth_failed', true)->count())->toBe(0)
        ->and(($this->account)($this->dev, 'Dev', 'dev', 'secret', ['alpha']))->toBeInstanceOf(QueryAccount::class);
});

test('an account without a password can be added when the servers accept that', function () {
    $this->mysql->users['nopass'] = '';
    $saved = $this->accounts->save($this->dev, null, ['label' => 'No password', 'username' => 'nopass', 'password' => '', 'servers' => [$this->ids['alpha']]]);

    expect($saved['account'])->toBeInstanceOf(QueryAccount::class)
        ->and($this->accounts->password($saved['account']->fresh()))->toBe('')
        ->and($this->query->run($this->dev, [$this->ids['alpha'] => $saved['account']->id], null, 'SELECT 1 AS one')['servers'][0]['ok'])->toBeTrue();
});

test('a login let in by socket identity (this site\'s own system user) is for admins only', function () {
    // Any password gets in, as unix_socket ignores it: the account is only the web server's identity.
    $dev = $this->accounts->save($this->dev, null, ['label' => 'Web', 'username' => 'www-data', 'password' => 'anything at all', 'servers' => [$this->ids['alpha']]]);
    $admin = $this->accounts->save($this->admin, null, ['label' => 'Web', 'username' => 'www-data', 'password' => '', 'servers' => [$this->ids['alpha']]]);

    expect($dev['account'])->toBeNull()
        ->and($dev['results'][0]['message'])->toContain('only admins')
        ->and($admin['account'])->toBeInstanceOf(QueryAccount::class)
        ->and($this->query->run($this->admin, [$this->ids['alpha'] => $admin['account']->id], null, 'SELECT 1 AS one')['servers'][0]['ok'])->toBeTrue();

    // An account a non-admin already has (e.g. from before) is refused when used, and when tested.
    $old = QueryAccount::query()->create(['user_id' => $this->dev->id, 'label' => 'Old', 'username' => 'www-data', 'servers' => [$this->ids['alpha']]]);
    $old->password = $this->cipher->encrypt('', "query-account:{$this->dev->id}:{$old->id}");
    $old->save();
    $run = $this->query->run($this->dev, [$this->ids['alpha'] => $old->id], null, 'SELECT 1 AS one');

    expect($run['servers'][0]['ok'])->toBeFalse()
        ->and($run['servers'][0]['message'])->toContain('only admins')
        ->and($run['auth_failed'])->toBeFalse()
        ->and($this->accounts->test($this->dev, $old)[0]['ok'])->toBeFalse()
        // Not a socket login (over TCP, or a password account): as before.
        ->and(QueryAccountService::socketIdentity(new PDO('sqlite::memory:')))->toBeFalse();
});
