<?php

use App\Services\DatabaseSetupService;
use App\Utils\DatabaseConfig;
use App\Utils\InstallCode;
use Illuminate\Database\Capsule\Manager;
use Leaf\Schema;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/db-setup-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
    putenv("DB_CONFIG={$this->dir}/connection.json");

    // A PDO that records what it's asked to run; "rows" answers the SELECTs by a fragment of their SQL.
    $this->fake = fn (array $rows = []) => new class ('sqlite::memory:', $rows) extends PDO {
        public array $ran = [];

        public function __construct(string $dsn, public array $rows)
        {
            parent::__construct($dsn);
        }

        public function exec(string $statement): int|false
        {
            $this->ran[] = $statement;

            return 0;
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            foreach ($this->rows as $fragment => $value) {
                if (str_contains($query, $fragment)) {
                    return parent::prepare('SELECT ' . $this->quote((string) $value) . ' WHERE ? IS NOT NULL' . str_repeat(' AND ? IS NOT NULL', substr_count($query, '?') - 1));
                }
            }

            return parent::prepare('SELECT 1 WHERE 0' . str_repeat(' AND ? IS NOT NULL', substr_count($query, '?')));
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
        {
            $this->ran[] = $query;
            $answer = collect($this->rows)->first(fn ($v, $fragment) => str_contains($query, $fragment));

            return is_array($answer) ? parent::query(implode(' UNION ALL ', array_map(fn ($row) => 'SELECT ' . implode(', ', array_map(fn ($v, $k) => $this->quote((string) $v) . ' AS ' . $k, $row, array_keys($row))), $answer))) : parent::query('SELECT 1');
        }
    };

    // Schema::migrate works on the global connection: make one for a file.
    $this->migrated = function (string $file): Manager {
        touch($file);
        $manager = new Manager();
        $manager->addConnection(['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
        $manager->setAsGlobal();
        Schema::setDbConnection($manager);

        foreach (glob('app/database/*.yml') as $schema) {
            Schema::migrate($schema);
        }

        return $manager;
    };
});

afterEach(function () {
    putenv('DB_CONFIG');
    array_map(fn ($f) => unlink("{$this->dir}/$f"), array_diff(scandir($this->dir) ?: [], ['.', '..']));
    rmdir($this->dir);
});

test('MariaDB: an admin account gets a database and a user limited to its tables; an existing user loses anything else', function () {
    $pdo = ($this->fake)();
    $messages = (new DatabaseSetupService())->provisionMysql($pdo, 'sysadmin', 'sysadmin', 'Secret123', 'localhost');

    expect($pdo->ran)->toBe([
        'CREATE DATABASE IF NOT EXISTS `sysadmin` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        "CREATE USER 'sysadmin'@'localhost' IDENTIFIED BY 'Secret123'",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON `sysadmin`.* TO 'sysadmin'@'localhost'",
    ])->and(implode(' ', $messages))->toContain("can't create databases or users");

    $pdo = ($this->fake)(['mysql.user' => 1, 'SCHEMATA' => 1]);
    (new DatabaseSetupService())->provisionMysql($pdo, 'app', 'app', 'Secret123', 'db.example.com');

    expect($pdo->ran)->toContain("ALTER USER 'app'@'%' IDENTIFIED BY 'Secret123'")
        ->toContain("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'app'@'%'")
        ->not->toContain("CREATE USER 'app'@'%' IDENTIFIED BY 'Secret123'");
});

test('PostgreSQL: a role that can\'t create databases or roles, and tables only in the public schema', function () {
    $pdo = ($this->fake)();
    $inside = ($this->fake)();
    $service = new DatabaseSetupService(fn (string $driver, array $settings) => $settings['database'] === 'sysadmin' ? $inside : $pdo);
    $service->provisionPgsql($pdo, ['host' => '/var/run/postgresql', 'port' => 5432, 'username' => 'admin', 'password' => ''], 'sysadmin', 'sysadmin', 'Secret123');

    expect($pdo->ran)->toBe([
        "CREATE ROLE \"sysadmin\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD 'Secret123'",
        "CREATE DATABASE \"sysadmin\" ENCODING 'UTF8' TEMPLATE template0",
    ])->and($inside->ran)->toBe([
        'REVOKE ALL ON DATABASE "sysadmin" FROM PUBLIC',
        'GRANT CONNECT, TEMPORARY ON DATABASE "sysadmin" TO "sysadmin"',
        'REVOKE CREATE ON SCHEMA public FROM PUBLIC',
        'GRANT USAGE, CREATE ON SCHEMA public TO "sysadmin"',
    ]);
});

test('admin rights are read from the grants (MariaDB/MySQL) or the role (PostgreSQL)', function () {
    $service = new DatabaseSetupService();
    $grants = fn (string ...$lines) => ($this->fake)(['SHOW GRANTS' => array_map(fn ($l) => ['g' => $l], $lines)]);

    expect($service->canAdminister('mysql', $grants('GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION')))->toBeTrue()
        ->and($service->canAdminister('mysql', $grants('GRANT SELECT, CREATE, CREATE USER ON *.* TO `ops`@`%` WITH GRANT OPTION')))->toBeTrue()
        ->and($service->canAdminister('mysql', $grants('GRANT ALL PRIVILEGES ON *.* TO `ops`@`%`')))->toBeFalse()
        ->and($service->canAdminister('mysql', $grants('GRANT USAGE ON *.* TO `app`@`%`', 'GRANT ALL PRIVILEGES ON `app`.* TO `app`@`%` WITH GRANT OPTION')))->toBeFalse()
        ->and($service->canAdminister('pgsql', ($this->fake)(['pg_roles' => [['rolsuper' => 'f', 'rolcreatedb' => 't', 'rolcreaterole' => 't']]])))->toBeTrue()
        ->and($service->canAdminister('pgsql', ($this->fake)(['pg_roles' => [['rolsuper' => 'f', 'rolcreatedb' => 't', 'rolcreaterole' => 'f']]])))->toBeFalse();
});

test('an admin account must not become the app\'s user (its rights would be taken away)', function () {
    $service = new DatabaseSetupService(fn () => ($this->fake)(['SHOW GRANTS' => [['g' => 'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION']]]));

    expect(fn () => $service->install(['driver' => 'mysql', 'host' => 'localhost', 'username' => 'root', 'database' => 'sysadmin', 'app_username' => 'ROOT']))
        ->toThrow(DomainException::class, 'a user of its own')
        ->and(fn () => $service->install(['driver' => 'mysql', 'host' => 'localhost', 'username' => 'root', 'database' => 'sys`x', 'app_username' => 'a']))
        ->toThrow(DomainException::class, 'database name');
});

test('SQLite: tables created, the old data copied with its ids, the connection saved with the password encrypted', function () {
    $old = ($this->migrated)("{$this->dir}/old.sqlite")->getConnection();
    $old->table('users')->insert(['id' => 7, 'username' => 'admin', 'role' => 'admin', 'session_version' => 3, 'must_change_password' => 0]);
    $old->table('health_checks')->insert(array_map(fn ($i) => ['server_id' => 1, 'check_key' => "k$i", 'status' => 'ok', 'summary' => str_repeat('é', 300), 'checked_at' => '2026-09-29 12:00:00'], range(1, 1203)));

    expect(DatabaseConfig::isConfigured())->toBeFalse();

    $messages = (new DatabaseSetupService())->install(['driver' => 'sqlite', 'database' => "{$this->dir}/new.sqlite"], "{$this->dir}/old.sqlite");
    $new = new PDO("sqlite:{$this->dir}/new.sqlite");

    expect(DatabaseConfig::isConfigured())->toBeTrue()
        ->and(DatabaseConfig::load())->toBe(['driver' => 'sqlite', 'database' => "{$this->dir}/new.sqlite"])
        ->and($new->query('SELECT id, session_version FROM users')->fetch(PDO::FETCH_NUM))->toBe([7, 3])
        ->and((int) $new->query('SELECT COUNT(*) FROM health_checks')->fetchColumn())->toBe(1203)
        ->and(implode(' ', $messages))->toContain('Copied 1204 rows');

    // Again: the new database has rows now, unless they're replaced.
    $manager = new Manager();
    $manager->addConnection(['driver' => 'sqlite', 'database' => "{$this->dir}/old.sqlite", 'prefix' => ''], 'old');
    $manager->addConnection(['driver' => 'sqlite', 'database' => "{$this->dir}/new.sqlite", 'prefix' => ''], 'new');
    $tables = array_map(fn ($f) => basename($f, '.yml'), glob('app/database/*.yml'));

    expect(fn () => (new DatabaseSetupService())->copy($manager->getConnection('old'), $manager->getConnection('new'), $tables))->toThrow(DomainException::class, 'already has rows')
        ->and((new DatabaseSetupService())->copy($manager->getConnection('old'), $manager->getConnection('new'), $tables, replace: true)['users'])->toBe(1);

    DatabaseConfig::save(['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'database' => 'sysadmin', 'username' => 'sysadmin', 'password' => 'Secret123'], null, $this->cipher);
    $stored = (string) file_get_contents("{$this->dir}/connection.json");

    expect($stored)->not->toContain('Secret123')
        ->and(DatabaseConfig::load(null, $this->cipher)['password'])->toBe('Secret123')
        ->and(DatabaseConfig::connection(DatabaseConfig::load(null, $this->cipher))['timezone'])->toBe('+00:00')
        ->and(fileperms("{$this->dir}/connection.json") & 0777)->toBe(0640);
});

test('install codes are long, random and compared exactly', function () {
    $code = InstallCode::current();

    expect($code)->toMatch('/^[a-z0-9]{5}(-[a-z0-9]{5}){3}$/')
        ->and(InstallCode::current())->toBe($code)
        ->and(InstallCode::matches(strtoupper(" $code ")))->toBeTrue()
        ->and(InstallCode::matches(substr($code, 0, -1)))->toBeFalse()
        ->and(DatabaseSetupService::generatePassword())->toMatch('/^[A-Za-z0-9]{32}$/');
});
