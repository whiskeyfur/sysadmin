<?php

namespace App\Services;

use App\DTOs\ServerTestResult;
use App\Exceptions\HostKeyUnknownException;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use App\Models\User;
use PDO;

/**
 * Connection test, for anyone signed in (see CheckCooldown): SSH (host
 * key, login, a harmless command) and, if configured, MySQL (connect and
 * read the version).
 */
class ServerTestService
{
    public function __construct(
        private readonly ServerService $servers = new ServerService(),
        private readonly SshService $ssh = new SshService(),
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly CheckCooldown $cooldown = new CheckCooldown(),
    ) {
    }

    /**
     * @throws \DomainException while a non-admin must wait (CheckCooldown)
     */
    public function test(User $user, Server $server): ServerTestResult
    {
        $this->cooldown->require($user, $server->last_tested_at, "{$server->name} was tested");

        [$sshOk, $sshMessage, $untrusted] = [null, null, null];

        if ($server->ssh_enabled) {
            try {
                $output = trim($this->ssh->run($server, 'uname -srm'));
                [$sshOk, $sshMessage] = [true, "connected ($output)."];
            } catch (HostKeyUnknownException $e) {
                [$sshOk, $sshMessage, $untrusted] = [false, 'the host key needs to be trusted first.', $e->presented];
            } catch (ServerConnectionException $e) {
                [$sshOk, $sshMessage] = [false, $e->getMessage()];
            }
        }

        [$mysqlOk, $mysqlMessage] = [null, null];

        if ($server->mysql_enabled) {
            try {
                $pdo = $this->mysql->connect($server);
                $version = $pdo->query('SELECT VERSION()')?->fetch(PDO::FETCH_COLUMN);
                $tls = $this->mysql->tls($pdo);
                $encryption = $tls === null
                    ? 'unencrypted'
                    : "{$tls['version']} {$tls['cipher']}" . ($server->mysql_tls === Server::TLS_ENCRYPT ? ', certificate not checked' : ', certificate verified');
                [$mysqlOk, $mysqlMessage] = [true, "connected ($version, $encryption)."];
            } catch (ServerConnectionException $e) {
                [$mysqlOk, $mysqlMessage] = [false, $e->getMessage()];
            }
        }

        $result = new ServerTestResult($sshOk, $sshMessage, $mysqlOk, $mysqlMessage, $untrusted);
        $this->servers->recordTest($server, $result->ok(), $result->summary());

        return $result;
    }
}
