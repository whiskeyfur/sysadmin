<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbQuery;
use App\Models\Server;
use App\Models\User;
use App\Services\MariadbQueryService;
use App\Services\MysqlService;
use App\Services\ServerService;

// Each "server" is an in-memory SQLite database; logins are checked against $users, stored-account
// logins are recorded.
class QueryToolFakeMysql extends MysqlService
{
    /** @var array<string, PDO> server name => database */
    public array $databases = [];

    /** @var array<string, string> username => password, for every server */
    public array $users = ['dev' => 'secret'];

    /** @var list<string> */
    public array $connections = [];

    public function __construct()
    {
    }

    public function connectAs(Server $server, string $username, string $password, ?string $database = null): PDO
    {
        $this->connections[] = "{$server->name} as $username";

        if (($this->users[$username] ?? null) !== $password) {
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
    $servers = new ServerService($this->cipher);
    $this->mysql = new QueryToolFakeMysql();

    foreach (['alpha', 'beta', 'gamma'] as $i => $name) {
        $server = $servers->create($this->admin, ['name' => $name, 'hostname' => "$name.example.com", 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'monitor', 'mysql_password' => 'monitor-pw', 'mysql_port' => 3306, 'mysql_tls' => 'off']);
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE orders (id INTEGER, total REAL, note TEXT)');

        for ($n = 1; $n <= $i + 2; $n++) {
            $pdo->exec("INSERT INTO orders VALUES ($n, " . ($n * 10) . ", " . ($n === 1 ? 'NULL' : "'order $n'") . ')');
        }

        $this->mysql->databases[$name] = $pdo;
        $this->ids[$name] = $server->id;
    }

    $this->query = new MariadbQueryService($this->mysql, $this->clock);
    $this->all = array_values($this->ids);
});

test('plain reads are told apart from statements that may change data', function (string $sql, bool $read) {
    expect(MariadbQueryService::isRead($sql))->toBe($read);
})->with([
    ['SELECT 1', true], ["  -- note\n/* x */ show databases", true], ['DESCRIBE t', true], ['WITH a AS (SELECT 1) SELECT * FROM a', true],
    ['UPDATE t SET a = 1', false], ['delete from t', false], ['DROP TABLE t', false], ["/* SELECT */ INSERT INTO t VALUES (1)", false], ['', false],
]);

test('one statement on every server, collated with the server first; rows per server limited', function () {
    $result = $this->query->run($this->dev, $this->all, 'dev', 'secret', null, "SELECT id, note FROM orders ORDER BY id;\n");

    expect($result['columns'])->toBe(['id', 'note'])
        ->and(array_column($result['rows'], 'server'))->toBe(['alpha', 'alpha', 'beta', 'beta', 'beta', 'gamma', 'gamma', 'gamma', 'gamma'])
        ->and($result['rows'][0]['values'])->toBe(['id' => '1', 'note' => null])
        ->and(array_column($result['servers'], 'message'))->toBe(['2 rows', '3 rows', '4 rows'])
        ->and($this->mysql->connections)->toBe(['alpha as dev', 'beta as dev', 'gamma as dev']);

    $limited = $this->query->run($this->dev, [$this->ids['gamma']], 'dev', 'secret', null, 'SELECT * FROM orders', 3);
    expect(count($limited['rows']))->toBe(3)
        ->and($limited['servers'][0]['truncated'])->toBeTrue()
        ->and($limited['servers'][0]['message'])->toBe('3 rows (the first 3)');

    // Logged without the password.
    $log = MariadbQuery::query()->orderBy('id')->first();
    expect($log->db_user)->toBe('dev')
        ->and($log->outcome)->toBe('3 ok, 0 failed')
        ->and($log->writes)->toBeFalse()
        ->and(json_encode($log->toArray()))->not->toContain('secret');
});

test('columns are merged across servers, and a server\'s error doesn\'t stop the rest', function () {
    $this->mysql->databases['beta']->exec('DROP TABLE orders');
    $this->mysql->databases['beta']->exec('CREATE TABLE orders (id INTEGER, customer TEXT)');
    $this->mysql->databases['beta']->exec("INSERT INTO orders VALUES (7, 'Ann')");
    $this->mysql->databases['gamma']->exec('DROP TABLE orders');

    $result = $this->query->run($this->dev, $this->all, 'dev', 'secret', null, 'SELECT * FROM orders WHERE id IN (1, 7)');

    expect($result['columns'])->toBe(['id', 'total', 'note', 'customer'])
        ->and($result['rows'][1])->toBe(['server' => 'beta', 'values' => ['id' => '7', 'customer' => 'Ann']])
        ->and($result['servers'][2]['ok'])->toBeFalse()
        ->and($result['servers'][2]['message'])->toContain('The statement failed')->toContain('no such table');
});

test('a statement that may change data runs only when confirmed, and says what it changed', function () {
    expect(fn () => $this->query->run($this->dev, $this->all, 'dev', 'secret', null, 'DELETE FROM orders WHERE id = 1'))->toThrow(DomainException::class, 'may change data on 3 servers');

    $result = $this->query->run($this->dev, $this->all, 'dev', 'secret', null, 'DELETE FROM orders WHERE id = 1', confirmedWrites: true);
    expect(array_column($result['servers'], 'message'))->toBe(['1 row affected', '1 row affected', '1 row affected'])
        ->and($result['columns'])->toBe([])
        ->and(MariadbQuery::query()->latest('id')->value('writes'))->toBeTrue();
});

test('when the first server refuses the login the others aren\'t tried; repeated refusals make the user wait', function () {
    $result = $this->query->run($this->dev, $this->all, 'dev', 'wrong', null, 'SELECT 1');

    expect($result['auth_failed'])->toBeTrue()
        ->and($this->mysql->connections)->toBe(['alpha as dev'])
        ->and($result['servers'][1]['message'])->toBe('Not tried: the first server refused the login.')
        ->and(MariadbQuery::query()->latest('id')->value('auth_failed'))->toBeTrue();

    for ($i = 1; $i < MariadbQueryService::AUTH_FAILURES; $i++) {
        $this->query->run($this->dev, $this->all, 'dev', 'wrong', null, 'SELECT 1');
    }

    expect(fn () => $this->query->run($this->dev, $this->all, 'dev', 'secret', null, 'SELECT 1'))->toThrow(DomainException::class, 'Too many refused database logins');

    $this->clock->advance(MariadbQueryService::AUTH_WINDOW_MINUTES * 60 + 1);
    expect($this->query->run($this->dev, $this->all, 'dev', 'secret', null, 'SELECT 1')['auth_failed'])->toBeFalse();
});

test('only admins can use the servers\' stored accounts', function () {
    expect(fn () => $this->query->run($this->dev, $this->all, '', '', null, 'SELECT 1', stored: true))->toThrow(AuthorizationException::class);

    $result = $this->query->run($this->admin, $this->all, '', '', null, 'SELECT COUNT(*) AS n FROM orders', stored: true);
    expect(array_column($result['rows'], 'values'))->toBe([['n' => '2'], ['n' => '3'], ['n' => '4']])
        ->and($this->mysql->connections)->toBe(['alpha as stored', 'beta as stored', 'gamma as stored'])
        ->and(MariadbQuery::query()->latest('id')->value('db_user'))->toBe('stored accounts');
});

test('input is checked before anything runs', function (array $args, string $message) {
    expect(fn () => $this->query->run($this->dev, ...$args))->toThrow(DomainException::class, $message);
    expect($this->mysql->connections)->toBe([]);
})->with([
    [[[1, 2, 3], 'dev', 'secret', null, '  ;  '], 'Type a statement'],
    [[[], 'dev', 'secret', null, 'SELECT 1'], 'Choose at least one server'],
    [[[1], '', '', null, 'SELECT 1'], 'Log in'],
    [[[1], 'dev', 'secret', 'shop; DROP', 'SELECT 1'], "isn't a database name"],
]);

test('binary values show as hex and long ones are cut', function () {
    $pdo = $this->mysql->databases['alpha'];
    $result = $this->query->run($this->dev, [$this->ids['alpha']], 'dev', 'secret', null, "SELECT X'00FF10' AS bin, '" . str_repeat('x', 2500) . "' AS long");

    expect($result['rows'][0]['values']['bin'])->toBe('0x00ff10')
        ->and(mb_strlen($result['rows'][0]['values']['long']))->toBe(MariadbQueryService::MAX_CELL + 1);
});
