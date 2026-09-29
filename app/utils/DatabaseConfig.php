<?php

namespace App\Utils;

use App\Services\SecretCipher;

/**
 * The app's database connection, as the install wizard (or `php leaf
 * app:db-setup`) saved it: storage/app/db/connection.json, or the file
 * DB_CONFIG names (dev servers point it at their own). The password is
 * encrypted with the app key (SecretCipher, context "db-password"), so a
 * copy of the file alone doesn't reveal it.
 *
 * Without the file, a DB_CONNECTION in .env still counts (installs from
 * before the wizard), unless DB_CONFIG is set. With neither, the app isn't
 * configured and every page leads to the wizard.
 */
class DatabaseConfig
{
    public const DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    public static function path(): string
    {
        $override = getenv('DB_CONFIG');

        return is_string($override) && $override !== '' ? $override : dirname(__DIR__, 2) . '/storage/app/db/connection.json';
    }

    /**
     * @return array<string, mixed>|null driver, database; for servers host, port, username, password
     */
    public static function load(?string $path = null, ?SecretCipher $cipher = null): ?array
    {
        $path ??= self::path();

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $saved = json_decode((string) file_get_contents($path), true);

        if (!is_array($saved) || !in_array($saved['driver'] ?? null, self::DRIVERS, true) || !is_string($saved['database'] ?? null)) {
            return null;
        }

        if (isset($saved['password']) && is_string($saved['password']) && $saved['password'] !== '') {
            try {
                $saved['password'] = ($cipher ?? new SecretCipher())->decrypt($saved['password'], 'db-password');
            } catch (\Throwable) {
                throw new \RuntimeException("The database password in $path can't be decrypted: APP_KEY has changed. Run `php leaf app:db-setup` again, or restore the old APP_KEY.");
            }
        }

        return $saved;
    }

    /**
     * Save a connection (the password encrypted), readable by the owner and the web server's group only.
     *
     * @param array<string, mixed> $settings driver, database; for servers host, port, username, password
     */
    public static function save(array $settings, ?string $path = null, ?SecretCipher $cipher = null): void
    {
        $path ??= self::path();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o2775, true);
        }

        if (isset($settings['password']) && $settings['password'] !== '') {
            $settings['password'] = ($cipher ?? new SecretCipher())->encrypt($settings['password'], 'db-password');
        }

        $temporary = "$path.tmp";

        if (!is_writable($directory) || file_put_contents($temporary, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Can't write $path: check that the web server may write to $directory.");
        }

        @chmod($temporary, 0o640);

        if (function_exists('posix_getgrnam') && posix_getgrnam('www-data') !== false) {
            @chgrp($temporary, 'www-data');
        }

        rename($temporary, $path);
    }

    /**
     * The saved connection as Laravel/Leaf connection settings, or null.
     *
     * @param array<string, mixed>|null $saved settings as saved (default: the saved ones)
     * @return array<string, mixed>|null
     */
    public static function connection(?array $saved = null): ?array
    {
        $saved ??= self::load();

        if ($saved === null) {
            return null;
        }

        return match ($saved['driver']) {
            'sqlite' => ['driver' => 'sqlite', 'database' => $saved['database'], 'prefix' => '', 'foreign_key_constraints' => true],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $saved['host'] ?? 'localhost',
                'port' => (string) ($saved['port'] ?? 3306),
                'unix_socket' => $saved['socket'] ?? '',
                'database' => $saved['database'],
                'username' => $saved['username'] ?? '',
                'password' => $saved['password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                // Times are stored in UTC: TIMESTAMP columns convert from the session's time zone.
                'timezone' => '+00:00',
            ],
            default => [
                'driver' => 'pgsql',
                'host' => $saved['host'] ?? 'localhost',
                'port' => (string) ($saved['port'] ?? 5432),
                'database' => $saved['database'],
                'username' => $saved['username'] ?? '',
                'password' => $saved['password'] ?? '',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
                'timezone' => 'UTC',
            ],
        };
    }

    /**
     * Whether the app has a database: the saved connection, or (without DB_CONFIG) a DB_CONNECTION in .env.
     */
    public static function isConfigured(): bool
    {
        if (is_file(self::path())) {
            return true;
        }

        $override = getenv('DB_CONFIG');

        return !(is_string($override) && $override !== '') && (string) _env('DB_CONNECTION', '') !== '';
    }
}
