<?php

namespace App\Services;

use App\DTOs\ServerTestResult;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HostKeyUnknownException;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use App\Models\User;
use PDO;

/**
 * Admin-triggered connection test: SSH (host key, login, a harmless
 * command) and, if configured, MySQL (connect and read the version).
 */
class ServerTestService
{
    public function __construct(
        private readonly ServerService $servers = new ServerService(),
        private readonly SshService $ssh = new SshService(),
        private readonly MysqlService $mysql = new MysqlService(),
    ) {
    }

    public function test(User $admin, Server $server): ServerTestResult
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can test servers.');
        }

        $untrusted = null;

        try {
            $output = trim($this->ssh->run($server, 'uname -srm'));
            [$sshOk, $sshMessage] = [true, "connected ($output)."];
        } catch (HostKeyUnknownException $e) {
            [$sshOk, $sshMessage, $untrusted] = [false, 'the host key needs to be trusted first.', $e->presented];
        } catch (ServerConnectionException $e) {
            [$sshOk, $sshMessage] = [false, $e->getMessage()];
        }

        [$mysqlOk, $mysqlMessage] = [null, null];

        if ($server->mysql_enabled) {
            try {
                $version = $this->mysql->connect($server)->query('SELECT VERSION()')?->fetch(PDO::FETCH_COLUMN);
                [$mysqlOk, $mysqlMessage] = [true, "connected ($version)."];
            } catch (ServerConnectionException $e) {
                [$mysqlOk, $mysqlMessage] = [false, $e->getMessage()];
            }
        }

        $result = new ServerTestResult($sshOk, $sshMessage, $mysqlOk, $mysqlMessage, $untrusted);
        $this->servers->recordTest($server, $result->ok(), $result->summary());

        return $result;
    }
}
