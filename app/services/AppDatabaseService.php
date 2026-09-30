<?php

namespace App\Services;

use App\Models\Setting;
use App\Utils\DatabaseConfig;
use Illuminate\Database\Connection;
use PDO;

/**
 * What database the app itself runs on, for App settings: the saved connection (never its password) and
 * what the live connection says (server version, signed-in user, how it's reached, encryption, the
 * app's tables and their size). Read only; nothing here changes the connection.
 */
class AppDatabaseService
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    /**
     * Label => value, in display order; values that can't be read say so.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $connection = $this->connection ?? Setting::query()->getConnection();

        if (!$connection instanceof Connection) {
            return ['Type' => 'unknown'];
        }

        $driver = $connection->getDriverName();
        $config = $connection->getConfig();
        $saved = is_file(DatabaseConfig::path());
        $details = [
            'Type' => ['mysql' => 'MariaDB / MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'][$driver] ?? $driver,
            'Settings from' => $saved ? DatabaseConfig::path() : '.env (DB_CONNECTION, set up before the install wizard)',
        ];

        if ($driver === 'sqlite') {
            $file = (string) ($config['database'] ?? '');
            $details['File'] = $file;
            $details['Size'] = is_file($file) ? Checks\FileIoCheck::size((int) filesize($file)) : 'unknown';
        } else {
            $socket = (string) ($config['unix_socket'] ?? '');
            $host = (string) ($config['host'] ?? '');
            // MySQL clients take "localhost" to mean the local socket: the port isn't used then.
            $details['Server'] = match (true) {
                $socket !== '' => "socket $socket",
                $driver === 'mysql' && $host === 'localhost' => 'localhost (this machine, over the local socket; the port isn\'t used)',
                default => $host . ':' . ($config['port'] ?? ''),
            };
            $details['Database'] = (string) ($config['database'] ?? '');
            $details['User'] = (string) ($config['username'] ?? '');
        }

        $pdo = $connection->getPdo();
        $details += $this->live($pdo, $driver);

        return array_filter($details, fn ($value) => $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function live(PDO $pdo, string $driver): array
    {
        $ask = function (string $sql) use ($pdo): string {
            try {
                return (string) ($pdo->query($sql)?->fetchColumn() ?? '');
            } catch (\PDOException) {
                return '';
            }
        };
        $live = [];

        if ($driver === 'mysql') {
            $live['Server version'] = $ask('SELECT VERSION()');
            $live['Signed in as'] = $ask('SELECT CURRENT_USER()');
            // e.g. "Localhost via UNIX socket" (a host of localhost uses the socket) or "127.0.0.1 via TCP/IP".
            $live['Connection'] = (string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            $cipher = '';

            try {
                $cipher = (string) ($pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")?->fetch(PDO::FETCH_NUM)[1] ?? '');
            } catch (\PDOException) {
            }

            $live['Encryption'] = $cipher !== '' ? "TLS ($cipher)" : (str_contains(strtolower($live['Connection']), 'socket') ? 'None (local socket)' : 'None');
            $size = $ask('SELECT CONCAT(COUNT(*), \'|\', COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        } elseif ($driver === 'pgsql') {
            $live['Server version'] = $ask('SHOW server_version');
            $live['Signed in as'] = $ask('SELECT current_user');
            $live['Encryption'] = $ask('SELECT CASE WHEN ssl THEN \'TLS (\' || cipher || \')\' ELSE \'None\' END FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
            $size = $ask("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public') || '|' || pg_database_size(current_database())");
        } else {
            $live['SQLite version'] = $ask('SELECT sqlite_version()');
            $size = $ask("SELECT COUNT(*) || '|' FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        }

        [$tables, $bytes] = array_pad(explode('|', $size, 2), 2, '');

        if ($tables !== '') {
            $live['Tables'] = number_format((int) $tables) . ($bytes !== '' ? ', ' . Checks\FileIoCheck::size((int) $bytes) : '');
        }

        return $live;
    }
}
