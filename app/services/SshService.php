<?php

namespace App\Services;

use App\DTOs\HostKey;
use App\Exceptions\HostKeyMismatchException;
use App\Exceptions\HostKeyUnknownException;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH to a monitored server with phpseclib and the app's key.
 *
 * The host key is always checked before logging in: an untrusted or changed
 * host key stops the connection before any credentials are sent.
 */
class SshService
{
    public const CONNECT_TIMEOUT = 10;

    public const COMMAND_TIMEOUT = 20;

    public function __construct(
        private readonly SshKeyService $keys = new SshKeyService(),
        private readonly ServerService $servers = new ServerService(),
    ) {
    }

    /**
     * Fetch the host key the server presents, without logging in.
     *
     * @throws ServerConnectionException
     */
    public function presentedHostKey(Server $server): HostKey
    {
        $this->requireSshEnabled($server);
        $ssh = $this->open($server);

        try {
            return $this->hostKeyOf($ssh);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Connect, verify the host key and log in the server's configured way:
     * the app's key, or the stored password for servers that refuse keys.
     *
     * @throws HostKeyUnknownException
     * @throws HostKeyMismatchException
     * @throws ServerConnectionException
     */
    public function connect(Server $server): SSH2
    {
        if ($server->ssh_auth === Server::SSH_AUTH_PASSWORD && $server->ssh_password_allowed) {
            return $this->connectWithPassword($server, (string) $this->servers->sshPassword($server));
        }

        return $this->connectWithKey($server);
    }

    /**
     * @throws HostKeyUnknownException
     * @throws HostKeyMismatchException
     * @throws ServerConnectionException
     */
    public function connectWithKey(Server $server): SSH2
    {
        return $this->connectVerified(
            $server,
            fn (SSH2 $ssh) => $ssh->login($server->ssh_username, $this->keys->privateKey()),
            "SSH key login as {$server->ssh_username} was refused. The app's public key may not be in that user's ~/.ssh/authorized_keys, or the server doesn't allow key login.",
        );
    }

    /**
     * Log in with a password. Refused outright unless the server allows
     * password login, and only ever sent after the host key is verified.
     *
     * @throws HostKeyUnknownException
     * @throws HostKeyMismatchException
     * @throws ServerConnectionException
     */
    public function connectWithPassword(Server $server, string $password): SSH2
    {
        if (!$server->ssh_password_allowed) {
            throw new ServerConnectionException("Password login is not allowed for {$server->name}; no password was tried.");
        }

        return $this->connectVerified(
            $server,
            fn (SSH2 $ssh) => $ssh->login($server->ssh_username, $password),
            "SSH password login as {$server->ssh_username} was refused: wrong password, or the server doesn't allow password login.",
        );
    }

    /**
     * Run a command on an open connection and return its output.
     *
     * @throws ServerConnectionException also when the command exits non-zero.
     */
    public function exec(SSH2 $ssh, string $command): string
    {
        $output = $ssh->exec($command);

        if ($ssh->isTimeout()) {
            throw new ServerConnectionException('The command timed out after ' . self::COMMAND_TIMEOUT . ' seconds.');
        }

        $status = $ssh->getExitStatus();

        if ($status !== false && $status !== 0) {
            throw new ServerConnectionException("The command exited with status $status: " . trim((string) $output));
        }

        return (string) $output;
    }

    /**
     * @param callable(SSH2): bool $login
     */
    private function connectVerified(Server $server, callable $login, string $refusedMessage): SSH2
    {
        $this->requireSshEnabled($server);

        $trusted = $server->ssh_host_key !== null ? HostKey::fromString($server->ssh_host_key) : null;
        $ssh = $this->open($server, $trusted);
        $presented = $this->hostKeyOf($ssh);

        if ($trusted === null) {
            $ssh->disconnect();

            throw new HostKeyUnknownException($presented);
        }

        if (!$trusted->sameKeyAs($presented)) {
            $ssh->disconnect();

            throw new HostKeyMismatchException($trusted, $presented);
        }

        try {
            $loggedIn = $login($ssh);
        } catch (Throwable $e) {
            $ssh->disconnect();

            throw new ServerConnectionException('SSH login failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$loggedIn) {
            $ssh->disconnect();

            throw new ServerConnectionException($refusedMessage);
        }

        $ssh->setTimeout(self::COMMAND_TIMEOUT);

        return $ssh;
    }

    /**
     * Run one command and return its output.
     *
     * @throws ServerConnectionException also when the command exits non-zero.
     */
    public function run(Server $server, string $command): string
    {
        $ssh = $this->connect($server);

        try {
            return $this->exec($ssh, $command);
        } finally {
            $ssh->disconnect();
        }
    }

    private function requireSshEnabled(Server $server): void
    {
        if (!$server->ssh_enabled) {
            throw new ServerConnectionException("SSH is turned off for {$server->name}; it wasn't contacted.");
        }
    }

    private function open(Server $server, ?HostKey $trusted = null): SSH2
    {
        try {
            $ssh = new SSH2($server->hostname, $server->ssh_port, self::CONNECT_TIMEOUT);

            if ($trusted !== null) {
                // Ask for the trusted key's algorithm so the server presents that key.
                $ssh->setPreferredAlgorithms(['hostkey' => $trusted->algorithms()]);
            }

            return $ssh;
        } catch (Throwable $e) {
            throw new ServerConnectionException("Couldn't connect to {$server->hostname}:{$server->ssh_port}: " . $e->getMessage(), 0, $e);
        }
    }

    private function hostKeyOf(SSH2 $ssh): HostKey
    {
        try {
            $key = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new ServerConnectionException('SSH connection failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($key)) {
            throw new ServerConnectionException('SSH connection failed: ' . ($ssh->getLastError() ?: 'no host key received.'));
        }

        return HostKey::fromString($key);
    }
}
