<?php

namespace App\Services;

use App\DTOs\HostKey;
use App\DTOs\SshSetupResult;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use App\Models\User;

/**
 * Sets up SSH access to a server:
 *
 * 1. Trust the host key, after the admin has checked its fingerprint. A
 *    password is never sent to a server whose host key isn't verified.
 * 2. Try the app's key. If that works, nothing else is needed.
 * 3. With a password (only if the server allows password login), log
 *    in, install the app's public key in ~/.ssh/authorized_keys, and try the
 *    key again.
 * 4. If the server still refuses key login, keep the password (encrypted)
 *    and use password login for this server.
 *
 * Each login is tried at most once per step, so a server running fail2ban
 * sees as few attempts as possible.
 */
class SshSetupService
{
    public function __construct(
        private readonly ServerService $servers = new ServerService(),
        private readonly SshService $ssh = new SshService(),
        private readonly SshKeyService $keys = new SshKeyService(),
    ) {
    }

    /**
     * @param string|null $confirmedFingerprint the fingerprint the admin checked, when the host key isn't trusted yet
     * @param string|null $password the SSH password, used once; null or empty to skip
     */
    public function setUp(User $admin, Server $server, ?string $confirmedFingerprint, ?string $password): SshSetupResult
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can set up servers.');
        }

        $result = new SshSetupResult();
        $password = $password === '' ? null : $password;

        if (!$server->ssh_enabled) {
            return $result->step(false, 'SSH is turned off for this server. Turn it on in the server settings first.')->finish(false);
        }

        if ($server->ssh_host_key === null && !$this->trustHostKey($admin, $server, $confirmedFingerprint, $result)) {
            return $result->finish(false);
        }

        if ($this->keyLoginWorks($server, $result)) {
            $this->servers->useKeyAuth($server);

            return $result->finish(true);
        }

        if (!$server->ssh_password_allowed) {
            $result->step(false, "Password login isn't allowed for this server, so no password was tried. Add the app's public key to {$server->ssh_username}'s ~/.ssh/authorized_keys by hand, then run setup again.");

            return $result->finish(false);
        }

        if ($password === null) {
            $result->step(false, "Enter {$server->ssh_username}'s SSH password to install the key, or add the app's public key by hand.");

            return $result->finish(false);
        }

        if (!$this->installKey($server, $password, $result)) {
            return $result->finish(false);
        }

        if ($this->keyLoginWorks($server, $result)) {
            $this->servers->useKeyAuth($server);
            $result->step(true, 'The password was not stored.');

            return $result->finish(true);
        }

        $this->servers->usePasswordAuth($server, $password);
        $result->step(true, "This server doesn't allow key login, so the app will log in with the password, stored encrypted. Turn on key login on the server (PubkeyAuthentication yes) and run setup again to stop storing it.");

        return $result->finish(true);
    }

    /**
     * An idempotent POSIX sh script: create ~/.ssh with safe permissions and
     * add the key line if it isn't there, starting on a new line.
     */
    public function installCommand(string $publicKey): string
    {
        $key = escapeshellarg(trim($publicKey));
        $script = 'umask 077 && mkdir -p ~/.ssh && touch ~/.ssh/authorized_keys'
            . ' && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys'
            . " && if ! grep -qxF $key ~/.ssh/authorized_keys; then"
            . ' if [ -s ~/.ssh/authorized_keys ] && [ -n "$(tail -c 1 ~/.ssh/authorized_keys)" ]; then echo >> ~/.ssh/authorized_keys; fi;'
            . " printf '%s\\n' $key >> ~/.ssh/authorized_keys; fi";

        // Run under sh whatever the user's login shell is.
        return 'sh -c ' . escapeshellarg($script);
    }

    private function trustHostKey(User $admin, Server $server, ?string $confirmedFingerprint, SshSetupResult $result): bool
    {
        try {
            $presented = $this->ssh->presentedHostKey($server);
        } catch (ServerConnectionException $e) {
            $result->step(false, $e->getMessage());

            return false;
        }

        if ($confirmedFingerprint === null || !hash_equals($presented->fingerprint(), $confirmedFingerprint)) {
            $result->step(false, 'The server now presents a different host key (' . $presented->fingerprint() . ') from the one you checked. Nothing was trusted or sent.');

            return false;
        }

        $this->servers->trustHostKey($admin, $server, $presented);
        $result->step(true, 'Trusted the host key ' . $presented->fingerprint() . '.');

        return true;
    }

    /**
     * Depends on the remote server, so two calls can differ (the key may have
     * been installed in between).
     *
     * @phpstan-impure
     */
    private function keyLoginWorks(Server $server, SshSetupResult $result): bool
    {
        try {
            $this->ssh->connectWithKey($server)->disconnect();
            $result->step(true, "Logged in as {$server->ssh_username} with the app's key.");

            return true;
        } catch (ServerConnectionException $e) {
            $result->step(false, $e->getMessage());

            return false;
        }
    }

    private function installKey(Server $server, string $password, SshSetupResult $result): bool
    {
        try {
            $connection = $this->ssh->connectWithPassword($server, $password);
        } catch (ServerConnectionException $e) {
            $result->step(false, $e->getMessage());

            return false;
        }

        try {
            $this->ssh->exec($connection, $this->installCommand($this->keys->publicKey()));
            $result->step(true, "Logged in with the password and added the app's public key to ~/.ssh/authorized_keys.");

            return true;
        } catch (ServerConnectionException $e) {
            $result->step(false, "Logged in with the password, but couldn't install the key: " . $e->getMessage());

            return false;
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * The host key a server presents, for the setup page. Null with a message on failure.
     *
     * @return array{0: HostKey|null, 1: string|null}
     */
    public function presentedHostKey(Server $server): array
    {
        try {
            return [$this->ssh->presentedHostKey($server), null];
        } catch (ServerConnectionException $e) {
            return [null, $e->getMessage()];
        }
    }
}
