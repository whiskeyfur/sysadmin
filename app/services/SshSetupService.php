<?php

namespace App\Services;

use App\DTOs\HostKey;
use App\DTOs\SshSetupResult;
use App\Enums\ServerPlatform;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use App\Models\User;
use phpseclib3\Net\SSH2;

/**
 * Sets up SSH access to a server:
 *
 * 1. Trust the host key, after the admin has checked its fingerprint on
 *    the server. Or, if the admin chooses, verify it through the account
 *    (setUpByLogin): log in, read the server's own host key files and trust
 *    the presented key only if it's among them.
 * 2. Try the app's key. If that works, nothing else is needed.
 * 3. With a password (only if the server allows password login), log
 *    in, install the app's public key in ~/.ssh/authorized_keys, and try the
 *    key again. The password is the one the admin typed, or else the one
 *    stored for the server's SSH account ("Log in as") in Accounts.
 *    A typed password that worked is saved (encrypted) to that account as
 *    its current password.
 * 4. If the server still refuses key login, use password login for this
 *    server, with that account's password.
 *
 * Each login is tried at most once per step, so a server running fail2ban
 * sees as few attempts as possible. Verifying through the account uses the
 * same password login to install the key.
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
     * @param string|null $confirmedFingerprint the fingerprint the admin checked (or was shown, with $verifyByLogin), when the host key isn't trusted yet
     * @param string|null $password the SSH password, used once; null or empty to use the account's stored password, if any
     * @param bool $verifyByLogin check a new host key through the account instead of the admin's own check
     */
    public function setUp(User $admin, Server $server, ?string $confirmedFingerprint, ?string $password, bool $verifyByLogin = false): SshSetupResult
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can set up servers.');
        }

        $result = new SshSetupResult();
        $password = $password === '' ? null : $password;

        if (!$server->ssh_enabled) {
            return $result->step(false, 'SSH is turned off for this server. Turn it on in the server settings first.')->finish(false);
        }

        if ($server->ssh_host_key === null && $verifyByLogin) {
            return $this->setUpByLogin($admin, $server, $confirmedFingerprint, $password, $result);
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

        $typed = $password !== null;
        $password = $this->passwordFor($server, $password, $result);

        if ($password === null || !$this->installKey($server, $password, $result)) {
            return $result->finish(false);
        }

        return $this->afterInstall($server, $password, $typed, $result);
    }

    /**
     * Trust a new host key by checking it from inside the server: log in with
     * the app's key (no secret sent) or, if password login is allowed, the
     * password; then read the server's host key files. With a password, the
     * same login installs the app's key.
     */
    private function setUpByLogin(User $admin, Server $server, ?string $shownFingerprint, ?string $password, SshSetupResult $result): SshSetupResult
    {
        try {
            [$presented, $platform] = $this->ssh->probe($server);
            $this->servers->recordPlatform($server, $platform);
        } catch (ServerConnectionException $e) {
            return $result->step(false, $e->getMessage())->finish(false);
        }

        if ($shownFingerprint === null) {
            return $result->step(false, 'Choose how to check the host key. Nothing was trusted or sent.')->finish(false);
        }

        if (!hash_equals($presented->fingerprint(), $shownFingerprint)) {
            return $result->step(false, 'The server now presents a different host key (' . $presented->fingerprint() . ') from the one shown. Nothing was trusted or sent.')->finish(false);
        }

        $typed = $password !== null;
        $usedPassword = null;

        try {
            $connection = $this->ssh->connectForVerification($server, $presented);
            $result->step(true, "Logged in as {$server->ssh_username} with the app's key.");
        } catch (ServerConnectionException $e) {
            $result->step(false, $e->getMessage());
            $connection = null;
        }

        if ($connection === null) {
            if (!$server->ssh_password_allowed) {
                return $result->step(false, "Password login isn't allowed for this server, so no password was tried. Add the app's public key by hand first, or check the fingerprint on the server instead.")->finish(false);
            }

            $usedPassword = $this->passwordFor($server, $password, $result);

            if ($usedPassword === null) {
                return $result->finish(false);
            }

            try {
                $connection = $this->ssh->connectForVerification($server, $presented, $usedPassword);
                $result->step(true, "Logged in as {$server->ssh_username} with the password.");
            } catch (ServerConnectionException $e) {
                return $result->step(false, $e->getMessage())->finish(false);
            }
        }

        try {
            $platform = $this->ssh->platformOf($connection);
            $this->servers->recordPlatform($server, $platform);
            $onServer = $this->ssh->hostKeysOnServer($connection, $platform);

            if ($onServer === []) {
                return $result->step(false, "Couldn't read the server's host key files, so nothing was trusted. Check the fingerprint on the server instead.")->finish(false);
            }

            if (array_filter($onServer, fn (HostKey $key) => $key->sameKeyAs($presented)) === []) {
                $message = "The key the server presented isn't in its own host key files, so nothing was trusted. Someone may be intercepting the connection.";

                return $result->step(false, $message . ($usedPassword === null ? '' : ' The password was sent before this check: change it.'))->finish(false);
            }

            $this->servers->trustHostKey($admin, $server, $presented);
            $result->step(true, 'Found the presented host key ' . $presented->fingerprint() . " in the server's own host key files and trusted it.");

            if ($usedPassword === null) {
                $this->servers->useKeyAuth($server);

                return $result->finish(true);
            }

            if (!$this->installOver($connection, $server, $platform, $result)) {
                return $result->finish(false);
            }
        } finally {
            $connection->disconnect();
        }

        return $this->afterInstall($server, $usedPassword, $typed, $result);
    }

    /**
     * The typed password, else the one stored for the server's account.
     */
    private function passwordFor(Server $server, ?string $typed, SshSetupResult $result): ?string
    {
        if ($typed !== null) {
            return $typed;
        }

        $stored = $this->servers->sshPassword($server);

        if ($stored === null) {
            $result->step(false, "No password is stored for {$server->ssh_username} in Accounts. Enter its SSH password to install the key, or add the app's public key by hand.");

            return null;
        }

        $result->step(true, "Using the password stored for {$server->ssh_username} in Accounts.");

        return $stored;
    }

    /**
     * The key is installed: save a typed password, then switch to key login,
     * or keep password login if the server refuses keys.
     */
    private function afterInstall(Server $server, string $password, bool $typed, SshSetupResult $result): SshSetupResult
    {
        if ($typed) {
            $this->servers->storeSshPassword($server, $password);
            $result->step(true, "Saved the password as the current password of {$server->ssh_username}@{$server->name} in Accounts.");
        }

        if ($this->keyLoginWorks($server, $result)) {
            $this->servers->useKeyAuth($server);

            return $result->finish(true);
        }

        $this->servers->usePasswordAuth($server, $password);
        $result->step(true, "This server doesn't allow key login, so the app will log in with that account's password. Turn on key login on the server (PubkeyAuthentication yes) and run setup again to switch to the key.");

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
            [$presented, $platform] = $this->ssh->probe($server);
            $this->servers->recordPlatform($server, $platform);
        } catch (ServerConnectionException $e) {
            $result->step(false, $e->getMessage());

            return false;
        }

        if ($confirmedFingerprint === null) {
            $result->step(false, 'Choose how to check the host key. Nothing was trusted or sent.');

            return false;
        }

        if (!hash_equals($presented->fingerprint(), $confirmedFingerprint)) {
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
            $connection = $this->ssh->connectWithKey($server);
            $this->servers->recordPlatform($server, $this->ssh->platformOf($connection));
            $connection->disconnect();
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

        $platform = $this->ssh->platformOf($connection);
        $this->servers->recordPlatform($server, $platform);

        try {
            return $this->installOver($connection, $server, $platform, $result);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Install the app's key over an open password login.
     */
    private function installOver(SSH2 $connection, Server $server, ServerPlatform $platform, SshSetupResult $result): bool
    {
        if ($platform === ServerPlatform::Windows) {
            $result->step(false, "Logged in with the password, but installing the key automatically only works on Linux/Unix servers. On this Windows server, add the app's public key by hand: to C:\\ProgramData\\ssh\\administrators_authorized_keys for an administrator account, otherwise to C:\\Users\\{$server->ssh_username}\\.ssh\\authorized_keys.");

            return false;
        }

        try {
            $this->ssh->exec($connection, $this->installCommand($this->keys->publicKey()));
            $result->step(true, "Logged in with the password and added the app's public key to ~/.ssh/authorized_keys.");

            return true;
        } catch (ServerConnectionException $e) {
            $result->step(false, "Logged in with the password, but couldn't install the key: " . $e->getMessage());

            return false;
        }
    }

    /**
     * The host key a server presents, for the setup page, and records its
     * platform. Null with a message on failure.
     *
     * @return array{0: HostKey|null, 1: string|null}
     */
    public function presentedHostKey(Server $server): array
    {
        try {
            [$hostKey, $platform] = $this->ssh->probe($server);
            $this->servers->recordPlatform($server, $platform);

            return [$hostKey, null];
        } catch (ServerConnectionException $e) {
            return [null, $e->getMessage()];
        }
    }
}
