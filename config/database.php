<?php

// The connection the install wizard saved (storage/app/db/connection.json); see App\Utils\DatabaseConfig.
$saved = \App\Utils\DatabaseConfig::connection();

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */
    // This app stores its own data in SQLite. Defaulting to it (not Leaf's
    // "mysql") means a missing or incomplete .env can't send migrations to a
    // local MySQL server's "forge" database.
    // The saved connection; else DB_CONNECTION from .env (installs from before the wizard); else none yet,
    // and every page leads to the wizard (the RequireDatabase middleware).
    'default' => $saved !== null ? 'app' : (\App\Utils\DatabaseConfig::isConfigured() ? _env('DB_CONNECTION', 'sqlite') : 'unconfigured'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by eloquent is shown below to make development simple.
    |
    |
    | All database work in eloquent is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */
    'connections' => array_filter([
        'app' => $saved,

        // Until a database is set up: nothing persists, and nothing should query it.
        'unconfigured' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => _env('DATABASE_URL'),
            'database' => _env('DB_DATABASE', AppPaths('databaseStorage') . '/database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => _env('DB_FOREIGN_KEYS', true),
            'journal_mode' => _env('DB_JOURNAL_MODE', 'wal'),
            'busy_timeout' => _env('DB_BUSY_TIMEOUT', 5000),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => _env('DATABASE_URL'),
            'host' => _env('DB_HOST', '127.0.0.1'),
            'port' => _env('DB_PORT', '3306'),
            // This app's .env names them DB_NAME, DB_USER and DB_PASS (Laravel's names still work).
            'database' => _env('DB_NAME', _env('DB_DATABASE', 'forge')),
            'username' => _env('DB_USER', _env('DB_USERNAME', 'forge')),
            'password' => _env('DB_PASS', _env('DB_PASSWORD', '')),
            'unix_socket' => _env('DB_SOCKET', ''),
            'charset' => _env('DB_CHARSET', 'utf8mb4'),
            'collation' => _env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // Times are stored in UTC: TIMESTAMP columns convert from the session's time zone, which is
            // the server's own (e.g. PDT) unless set.
            'timezone' => '+00:00',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80400 ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => _env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => _env('DATABASE_URL'),
            'host' => _env('DB_HOST', '127.0.0.1'),
            'port' => _env('DB_PORT', '5432'),
            'database' => _env('DB_DATABASE', 'forge'),
            'username' => _env('DB_USERNAME', 'forge'),
            'password' => _env('DB_PASSWORD', ''),
            'charset' => _env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => _env('DATABASE_URL'),
            'host' => _env('DB_HOST', 'localhost'),
            'port' => _env('DB_PORT', '1433'),
            'database' => _env('DB_DATABASE', 'forge'),
            'username' => _env('DB_USERNAME', 'forge'),
            'password' => _env('DB_PASSWORD', ''),
            'charset' => _env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
        ],
    ]),
];
