<?php

namespace App\Console;

use App\Services\DatabaseSetupService;
use App\Utils\DatabaseConfig;
use App\Utils\FileSemaphore;
use Leaf\Sprout\Command;

/**
 * `php leaf app:db-setup`: what the install wizard does, from the shell
 * (e.g. with an admin that logs in over the local socket, which the web
 * server can't). Creates the database and the app's own restricted user
 * when the account may, creates the tables, copies the data of the SQLite
 * database from before (if any) and saves the connection.
 */
class AppDbSetupCommand extends Command
{
    protected $signature = 'app:db-setup
        {--driver=mysql : mysql, pgsql or sqlite}
        {--host= : Database server (default DB_HOST from .env, else localhost)}
        {--port= : Port (default 3306 or 5432)}
        {--database= : Database name, or the SQLite file (default DB_NAME from .env)}
        {--app-user= : The user to create for the app (default DB_USER from .env)}
        {--admin-user= : Account to connect with (default your own login; its password in DB_ADMIN_PASSWORD)}
        {--socket= : Socket for the admin login only (MariaDB/MySQL default the usual one on localhost; PostgreSQL its directory, e.g. /var/run/postgresql)}
        {--no-copy : Start empty instead of copying the existing SQLite data}
        {--replace : Replace rows already in the new database}
        {--force : Set up again although a connection is saved}';

    protected $description = 'Set up the app database (as the install wizard does)';

    protected $help = 'With an account that may create databases and users, creates the database and a user for the app that can only work with tables in it (generated password), creates the tables, copies the existing SQLite data and saves the connection in storage/app/db/connection.json. Scheduled checks wait meanwhile.';

    protected function handle()
    {
        if (is_file(DatabaseConfig::path()) && !$this->option('force')) {
            $this->comment('A database connection is already saved (' . DatabaseConfig::path() . '). Use --force to set up again.');

            return 1;
        }

        $driver = (string) $this->option('driver');
        $local = function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();
        $host = (string) ($this->option('host') ?: _env('DB_HOST', 'localhost'));
        $adminUser = (string) ($this->option('admin-user') ?: $local);
        $socket = $this->option('socket') ?: ($driver === 'mysql' && $host === 'localhost' ? (ini_get('pdo_mysql.default_socket') ?: '/run/mysqld/mysqld.sock') : null);
        $legacy = $this->option('no-copy') ? null : DatabaseSetupService::legacySqlite();

        $input = [
            'driver' => $driver,
            'host' => $host,
            'port' => $this->option('port'),
            'database' => (string) ($this->option('database') ?: _env('DB_NAME', 'sysadmin')),
            'app_username' => (string) ($this->option('app-user') ?: _env('DB_USER', 'sysadmin')),
            'username' => $adminUser,
            'password' => (string) (getenv('DB_ADMIN_PASSWORD') ?: ''),
            'socket' => $socket,
        ];

        // Scheduled checks write to the database: none while it moves.
        $lock = new FileSemaphore(StoragePath('framework/scheduler'), 1);

        if (!$lock->tryAcquire()) {
            $this->error('Scheduled checks are running right now; try again in a minute.');

            return 1;
        }

        try {
            foreach ((new DatabaseSetupService())->install($input, $legacy, (bool) $this->option('replace')) as $line) {
                $this->writeln("  $line");
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->comment('Nothing was saved: the app keeps its current database.');

            return 1;
        } finally {
            $lock->release();
        }

        if ($legacy !== null) {
            $this->comment("  The old SQLite file is kept as a backup: $legacy");
        }

        return 0;
    }
}
