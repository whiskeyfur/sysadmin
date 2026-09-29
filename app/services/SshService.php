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

    public function __construct(private readonly SshKeyService $keys = new SshKeyService())
    {
    }

    /**
     * Fetch the host key the server presents, without logging in.
     *
     * @throws ServerConnectionException
     */
    public function presentedHostKey(Server $server): HostKey
    {
        $ssh = $this->open($server);

        try {
            return $this->hostKeyOf($ssh);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Connect, verify the host key and log in.
     *
     * @throws HostKeyUnknownException
     * @throws HostKeyMismatchException
     * @throws ServerConnectionException
     */
    public function connect(Server $server): SSH2
    {
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
            $loggedIn = $ssh->login($server->ssh_username, $this->keys->privateKey());
        } catch (Throwable $e) {
            throw new ServerConnectionException('SSH login failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$loggedIn) {
            $ssh->disconnect();

            throw new ServerConnectionException(
                "SSH login as {$server->ssh_username} was refused. Check that the app's public key is in that user's ~/.ssh/authorized_keys.",
            );
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
            $output = $ssh->exec($command);

            if ($ssh->isTimeout()) {
                throw new ServerConnectionException('The command timed out after ' . self::COMMAND_TIMEOUT . ' seconds.');
            }

            $status = $ssh->getExitStatus();

            if ($status !== false && $status !== 0) {
                throw new ServerConnectionException("The command exited with status $status: " . trim((string) $output));
            }

            return (string) $output;
        } finally {
            $ssh->disconnect();
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
