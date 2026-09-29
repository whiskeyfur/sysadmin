<?php

namespace App\Services;

use App\Utils\DatabaseConfig;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Leaf\Schema;
use PDO;

/**
 * Setting up the app's database (the install wizard and `php leaf
 * app:db-setup`): MariaDB/MySQL, PostgreSQL or an SQLite file.
 *
 * When the account given may create databases and users, it creates the
 * database and a user of its own for the app that can only work inside it
 * (tables yes; databases, schemas, users and grants no). Otherwise the
 * account is used as it is, on a database that must already exist. Then
 * the tables are created from the schema files, the data of an existing
 * SQLite database can be copied in, and the connection is saved
 * (DatabaseConfig).
 */
class DatabaseSetupService
{
    public const LABELS = ['mysql' => 'MariaDB / MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite file (no server)'];

    public const DEFAULT_PORTS = ['mysql' => 3306, 'pgsql' => 5432];

    public const PASSWORD_LENGTH = 32;

    /**
     * What the app's own database user may do (MariaDB/MySQL): work with tables in its database only.
     */
    public const MYSQL_PRIVILEGES = 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES';

    /**
     * @var (callable(string, array<string, mixed>): PDO)|null how to connect (tests pass a fake)
     */
    private $connector;

    public function __construct(?callable $connector = null)
    {
        $this->connector = $connector;
    }

    public static function available(string $driver): bool
    {
        return isset(self::LABELS[$driver]) && extension_loaded("pdo_$driver");
    }

    /**
     * An SQLite database from before the wizard, whose data can be copied into the new one.
     */
    public static function legacySqlite(): ?string
    {
        $file = dirname(__DIR__, 2) . '/storage/app/db/database.sqlite';

        if (!is_file($file) || filesize($file) === 0) {
            return null;
        }

        try {
            $pdo = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn() !== false ? $file : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Set the database up and save the connection.
     *
     * @param array<string, mixed> $input driver; for servers host, port, username, password, database and
     *                                    app_username (the user to create when the account is an admin),
     *                                    optionally socket (for the admin login only); for SQLite database (a path)
     * @param string|null $copyFrom an SQLite file whose data to copy in
     * @param bool $replace empty tables that already have rows before copying
     * @return list<string> what was done
     *
     * @throws \DomainException with a message for the person setting up
     */
    public function install(array $input, ?string $copyFrom = null, bool $replace = false): array
    {
        $driver = (string) ($input['driver'] ?? '');

        if (!isset(self::LABELS[$driver])) {
            throw new \DomainException('Choose a database type.');
        }

        if (!self::available($driver)) {
            throw new \DomainException(self::LABELS[$driver] . " needs PHP's pdo_$driver extension, which isn't installed (or the web server hasn't been restarted since).");
        }

        [$settings, $messages] = $driver === 'sqlite' ? $this->sqlite($input) : $this->server($driver, $input);

        $manager = new Manager();
        $manager->addConnection(DatabaseConfig::connection($settings) ?? [], 'default');

        if ($copyFrom !== null) {
            $manager->addConnection(['driver' => 'sqlite', 'database' => $copyFrom, 'prefix' => ''], 'copy-from');
        }

        $manager->setAsGlobal();
        Schema::setDbConnection($manager);

        foreach (glob(dirname(__DIR__) . '/database/*.yml') ?: [] as $schema) {
            Schema::migrate($schema);
        }

        $messages[] = 'Tables created from the schema files.';

        if ($copyFrom !== null) {
            $tables = array_map(fn ($f) => basename($f, '.yml'), glob(dirname(__DIR__) . '/database/*.yml') ?: []);
            $copied = $this->copy($manager->getConnection('copy-from'), $manager->getConnection('default'), $tables, $replace);
            $messages[] = 'Copied ' . array_sum($copied) . " rows from $copyFrom (" . implode(', ', array_map(fn ($t, $n) => "$t $n", array_keys(array_filter($copied)), array_filter($copied))) . ').';
        }

        DatabaseConfig::save($settings);
        $messages[] = 'Connection saved in ' . DatabaseConfig::path() . ' (the password encrypted with the app key).';

        return $messages;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function sqlite(array $input): array
    {
        $file = trim((string) ($input['database'] ?? ''));

        if (!str_starts_with($file, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $file) !== 1) {
            throw new \DomainException('Give the SQLite file as a full path.');
        }

        if (!is_file($file) && (!is_dir(dirname($file)) || !is_writable(dirname($file)) || @touch($file) === false)) {
            throw new \DomainException("Can't create $file: the web server needs to be able to write to " . dirname($file) . '.');
        }

        return [['driver' => 'sqlite', 'database' => $file], ["Using the SQLite file $file."]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function server(string $driver, array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0) ?: self::DEFAULT_PORTS[$driver];
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $appUser = trim((string) ($input['app_username'] ?? '')) ?: $database;

        if ($host === '' || preg_match('/^[A-Za-z0-9.:\[\]_\/-]+$/', $host) !== 1) {
            throw new \DomainException('Give the database server\'s hostname or IP address.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \DomainException('The port must be between 1 and 65535.');
        }

        if ($username === '') {
            throw new \DomainException('Give the account to connect with.');
        }

        foreach (['database' => $database, 'app user' => $appUser] as $what => $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', $name) !== 1) {
                throw new \DomainException("The $what name must start with a letter and have up to 32 letters, digits or underscores.");
            }
        }

        $target = ['driver' => $driver, 'host' => $host, 'port' => $port, 'socket' => $input['socket'] ?? null];

        try {
            $admin = $this->connect($driver, $target + ['database' => $driver === 'pgsql' ? 'postgres' : null, 'username' => $username, 'password' => $password]);
        } catch (\PDOException $e) {
            if ($driver !== 'pgsql') {
                throw new \DomainException("Can't connect as $username: " . $this->reason($e));
            }

            $admin = null; // may not reach the "postgres" database: try the app's own below
        }

        if ($admin !== null && $this->canAdminister($driver, $admin)) {
            if (strcasecmp($appUser, $username) === 0) {
                throw new \DomainException("$username may create databases and users: give the app a user of its own (not $username), which will get access to $database only.");
            }

            $appPassword = self::generatePassword();
            $messages = $driver === 'mysql'
                ? $this->provisionMysql($admin, $database, $appUser, $appPassword, $host)
                : $this->provisionPgsql($admin, $target + ['username' => $username, 'password' => $password], $database, $appUser, $appPassword);
            $settings = ['driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $appUser, 'password' => $appPassword];
        } else {
            $settings = ['driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $username, 'password' => $password];
            $messages = ["$username can't create databases and users, so the app uses it as it is, on the existing database $database."];
        }

        try {
            $this->connect($driver, $settings)->query('SELECT 1');
        } catch (\PDOException $e) {
            throw new \DomainException("Can't connect to $database as {$settings['username']}: " . $this->reason($e));
        }

        return [$settings, $messages];
    }

    /**
     * Whether the account may create databases and users (and grant access to them).
     */
    public function canAdminister(string $driver, PDO $pdo): bool
    {
        if ($driver === 'pgsql') {
            $role = $pdo->query('SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user')->fetch(PDO::FETCH_ASSOC);

            return is_array($role) && (self::truthy($role['rolsuper']) || (self::truthy($role['rolcreatedb']) && self::truthy($role['rolcreaterole'])));
        }

        foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/^GRANT (.+) ON \*\.\* TO .* WITH GRANT OPTION/', (string) $grant, $m) !== 1) {
                continue;
            }

            $privileges = array_map('trim', explode(',', strtoupper($m[1])));

            if (in_array('ALL PRIVILEGES', $privileges, true) || (in_array('CREATE', $privileges, true) && in_array('CREATE USER', $privileges, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The database, and a user with table privileges on it only (an existing user loses anything else).
     *
     * @return list<string>
     */
    public function provisionMysql(PDO $admin, string $database, string $user, string $password, string $host): array
    {
        $exists = fn (string $sql, array $params) => ($s = $admin->prepare($sql)) && $s->execute($params) && $s->fetchColumn() !== false;
        $from = in_array($host, ['localhost'], true) ? 'localhost' : (in_array($host, ['127.0.0.1', '::1'], true) ? $host : '%');
        $account = $admin->quote($user) . '@' . $admin->quote($from);
        $messages = [];

        $messages[] = $exists('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]) ? "Database $database already existed." : "Created the database $database.";
        $admin->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if ($exists('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?', [$user, $from])) {
            $admin->exec("ALTER USER $account IDENTIFIED BY " . $admin->quote($password));
            $admin->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
            $messages[] = "User $user@$from already existed: gave it a new password and took away its other privileges.";
        } else {
            $admin->exec("CREATE USER $account IDENTIFIED BY " . $admin->quote($password));
            $messages[] = "Created the user $user@$from with a generated password.";
        }

        $admin->exec('GRANT ' . self::MYSQL_PRIVILEGES . " ON `$database`.* TO $account");
        $messages[] = "$user may work with tables in $database only: it can't create databases or users, or grant anything.";

        return $messages;
    }

    /**
     * The database, and a login role that owns nothing but may create tables in its public schema
     * (not schemas, databases or roles).
     *
     * @param array<string, mixed> $admin how to connect as the admin (to reach the new database)
     * @return list<string>
     */
    public function provisionPgsql(PDO $pdo, array $admin, string $database, string $user, string $password): array
    {
        $exists = fn (string $sql, array $params) => ($s = $pdo->prepare($sql)) && $s->execute($params) && $s->fetchColumn() !== false;
        $messages = [];
        $flags = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD ' . $pdo->quote($password);

        if ($exists('SELECT 1 FROM pg_roles WHERE rolname = ?', [$user])) {
            $pdo->exec("ALTER ROLE \"$user\" $flags");
            $messages[] = "Role $user already existed: gave it a new password; it can't create databases or roles.";
        } else {
            $pdo->exec("CREATE ROLE \"$user\" $flags");
            $messages[] = "Created the role $user with a generated password.";
        }

        if ($exists('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
            $messages[] = "Database $database already existed.";
        } else {
            $pdo->exec("CREATE DATABASE \"$database\" ENCODING 'UTF8' TEMPLATE template0");
            $messages[] = "Created the database $database.";
        }

        $inside = $this->connect('pgsql', ['database' => $database] + $admin);
        $inside->exec("REVOKE ALL ON DATABASE \"$database\" FROM PUBLIC");
        $inside->exec("GRANT CONNECT, TEMPORARY ON DATABASE \"$database\" TO \"$user\"");
        $inside->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $inside->exec("GRANT USAGE, CREATE ON SCHEMA public TO \"$user\"");
        $messages[] = "$user may create and use tables in $database's public schema only: not schemas, databases or roles.";

        return $messages;
    }

    /**
     * Letters and digits only, at least one of each kind.
     */
    public static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

        do {
            $password = '';

            for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
                $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (preg_match('/[A-Z]/', $password) !== 1 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1);

        return $password;
    }

    /**
     * Copy every row of the tables, ids included. The target tables must
     * exist (migrated from the same schema files) and be empty, unless
     * $replace empties them first. PostgreSQL gets real booleans, and its
     * id sequences continue after the copied ids.
     *
     * @param list<string> $tables
     * @return array<string, int> rows copied per table
     *
     * @throws \DomainException when a target table has rows (and not $replace) or the counts differ afterwards
     */
    public function copy(Connection $from, Connection $to, array $tables, bool $replace = false): array
    {
        $tables = array_values(array_filter($tables, fn ($t) => $from->getSchemaBuilder()->hasTable($t) && $to->getSchemaBuilder()->hasTable($t)));

        foreach ($tables as $table) {
            if (!$replace && $to->table($table)->exists()) {
                throw new \DomainException("The $table table in the new database already has rows. Nothing was copied; choose to replace them to copy again.");
            }
        }

        $postgres = $to->getDriverName() === 'pgsql';
        $copied = [];

        foreach ($tables as $table) {
            $columns = array_values(array_intersect($from->getSchemaBuilder()->getColumnListing($table), $to->getSchemaBuilder()->getColumnListing($table)));
            $booleans = $postgres ? array_column(array_filter($to->getSchemaBuilder()->getColumns($table), fn ($c) => in_array(strtolower((string) $c['type_name']), ['bool', 'boolean'], true)), 'name') : [];

            $to->transaction(function () use ($from, $to, $table, $columns, $booleans, $replace, &$copied) {
                if ($replace) {
                    $to->table($table)->delete();
                }

                $count = 0;
                $query = $from->table($table)->select($columns)->orderBy(in_array('id', $columns, true) ? 'id' : $columns[0]);

                foreach ($query->lazy(500)->chunk(500) as $chunk) {
                    $rows = $chunk->map(function ($row) use ($booleans) {
                        $row = (array) $row;

                        foreach ($booleans as $column) {
                            $row[$column] = $row[$column] === null ? null : (bool) $row[$column];
                        }

                        return $row;
                    })->all();
                    $to->table($table)->insert($rows);
                    $count += count($rows);
                }

                $copied[$table] = $count;
            });

            if ($postgres && in_array('id', $columns, true)) {
                $to->statement("SELECT setval(pg_get_serial_sequence('\"$table\"', 'id'), COALESCE((SELECT MAX(id) FROM \"$table\"), 1), (SELECT COUNT(*) FROM \"$table\") > 0)");
            }

            $source = $from->table($table)->count();
            $target = $to->table($table)->count();

            if ($source !== $target) {
                throw new \DomainException("$table: $source rows in the old database but $target in the new one.");
            }
        }

        return $copied;
    }

    /**
     * @param array<string, mixed> $settings driver, host, port, socket (optional), database (optional), username, password
     */
    private function connect(string $driver, array $settings): PDO
    {
        if ($this->connector !== null) {
            return ($this->connector)($driver, $settings);
        }

        $database = $settings['database'] ?? null;

        if ($driver === 'mysql') {
            $dsn = !empty($settings['socket'])
                ? "mysql:unix_socket={$settings['socket']}"
                : "mysql:host={$settings['host']};port={$settings['port']}";
            $dsn .= $database ? ";dbname=$database" : '';
        } else {
            // PostgreSQL's socket is a directory given as the host.
            $dsn = 'pgsql:host=' . (!empty($settings['socket']) ? $settings['socket'] : $settings['host']) . ";port={$settings['port']};dbname=" . ($database ?: 'postgres');
        }

        return new PDO($dsn, (string) $settings['username'], (string) ($settings['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    }

    private function reason(\PDOException $e): string
    {
        return trim((string) preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $e->getMessage()));
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
