<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use PDO;
use PDOException;

/**
 * PDO connections to a server's MariaDB/MySQL, over direct TCP.
 */
class MysqlService
{
    public const CONNECT_TIMEOUT = 5;

    public function __construct(private readonly ServerService $servers = new ServerService())
    {
    }

    /**
     * @throws ServerConnectionException
     */
    public function connect(Server $server): PDO
    {
        if (!$server->mysql_enabled) {
            throw new ServerConnectionException('MySQL is not configured for this server.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $server->mysqlHost(), $server->mysql_port);

        try {
            return new PDO($dsn, (string) $server->mysql_username, (string) $this->servers->mysqlPassword($server), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new ServerConnectionException("MySQL connection to {$server->mysqlHost()}:{$server->mysql_port} failed: " . $e->getMessage(), 0, $e);
        }
    }
}
