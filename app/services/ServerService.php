<?php

namespace App\Services;

use App\DTOs\HostKey;
use App\Enums\ServerPlatform;
use App\Exceptions\AuthorizationException;
use App\Models\Account;
use App\Models\HealthCheck;
use App\Models\Server;
use App\Models\SslCheck;
use App\Models\User;
use Carbon\Carbon;
use DomainException;

/**
 * Server configurations. Everyone can list servers; only admins can create,
 * change, delete or trust host keys. The MySQL password is encrypted and
 * write-only: it is never returned to a view.
 */
class ServerService
{
    private ?AccountService $accounts = null;

    public function __construct(
        private readonly SecretCipher $cipher = new SecretCipher(),
        private readonly CaCertificateService $certificates = new CaCertificateService(),
    ) {
    }

    /**
     * @return list<Server>
     */
    public function all(): array
    {
        return Server::query()->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws DomainException with a user-facing message when the input is invalid.
     */
    public function create(User $admin, array $input): Server
    {
        $this->requireAdmin($admin);

        $server = new Server();
        $this->fill($server, $input, true);

        return $server;
    }

    /**
     * Changing the hostname or SSH port forgets the trusted host key.
     *
     * @param array<string, mixed> $input
     *
     * @throws DomainException
     */
    public function update(User $admin, Server $server, array $input): void
    {
        $this->requireAdmin($admin);
        $this->fill($server, $input, false);
    }

    public function delete(User $admin, Server $server): void
    {
        $this->requireAdmin($admin);

        $server->getConnection()->transaction(function () use ($server) {
            HealthCheck::query()->where('server_id', $server->id)->delete();
            SslCheck::query()->where('server_id', $server->id)->delete();
            $server->delete();
        });
    }

    /**
     * Trust a host key the admin has checked on the server.
     */
    public function trustHostKey(User $admin, Server $server, HostKey $hostKey): void
    {
        $this->requireAdmin($admin);
        $server->ssh_host_key = $hostKey->toString();
        $server->save();
    }

    /**
     * Remember the platform read from the server's SSH banner.
     */
    public function recordPlatform(Server $server, ServerPlatform $platform): void
    {
        if ($platform !== ServerPlatform::Unknown && $server->ssh_platform !== $platform->value) {
            $server->ssh_platform = $platform->value;
            $server->save();
        }
    }

    /**
     * Log in with the app's key from now on. The account keeps its password:
     * it's tracked, even when the app doesn't need it.
     */
    public function useKeyAuth(Server $server): void
    {
        $server->ssh_auth = Server::SSH_AUTH_KEY;
        $server->ssh_password = null;
        $server->save();
    }

    /**
     * Fallback for servers that refuse key login: log in with the password
     * of the server's SSH account.
     */
    public function usePasswordAuth(Server $server, string $password): void
    {
        if (!$server->ssh_password_allowed) {
            throw new DomainException("Password login is not allowed for {$server->name}.");
        }

        $this->storeSshPassword($server, $password);
        $server->ssh_auth = Server::SSH_AUTH_PASSWORD;
        $server->save();
    }

    /**
     * Save a password that worked for the server's SSH account.
     */
    public function storeSshPassword(Server $server, string $password): void
    {
        $account = $this->accounts()->forServer($server);

        if ($account !== null) {
            $this->accounts()->storePassword($account, $password);
        }
    }

    /**
     * The password of the server's SSH account, for password logins.
     */
    public function sshPassword(Server $server): ?string
    {
        $account = $this->accounts()->forServer($server);

        return $account === null ? null : $this->accounts()->password($account);
    }

    /**
     * Whether the server's SSH account has a stored password (without decrypting it).
     */
    public function hasSshPassword(Server $server): bool
    {
        return $this->accounts()->forServer($server)?->hasPassword() ?? false;
    }

    /**
     * An SSH password stored on the server itself, from before accounts
     * existed; AccountService::syncServers() moves it into the account.
     */
    public function legacySshPassword(Server $server): ?string
    {
        return $server->ssh_password === null ? null : $this->cipher->decrypt($server->ssh_password, $this->sshPasswordContext($server));
    }

    /**
     * Note a successful SSH login with the server's account.
     */
    public function recordSshUse(Server $server): void
    {
        $this->accounts()->recordUse($server);
    }

    private function accounts(): AccountService
    {
        return $this->accounts ??= new AccountService($this->cipher);
    }

    public function mysqlPassword(Server $server): ?string
    {
        return $server->mysql_password === null ? null : $this->cipher->decrypt($server->mysql_password, $this->passwordContext($server));
    }

    /**
     * Record the outcome of a connection test.
     */
    public function recordTest(Server $server, bool $ok, string $message): void
    {
        $server->last_tested_at = Carbon::now();
        $server->last_test_ok = $ok;
        $server->last_test_message = mb_substr($message, 0, 1000);
        $server->save();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fill(Server $server, array $input, bool $creating): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        $hostname = strtolower(trim((string) ($input['hostname'] ?? '')));
        // The form always sends ssh_enabled; callers that predate optional SSH don't.
        $sshEnabled = array_key_exists('ssh_enabled', $input) ? filter_var($input['ssh_enabled'], FILTER_VALIDATE_BOOL) : true;
        $mysqlEnabled = filter_var($input['mysql_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $mysqlPassword = '';

        if ($name === '' || mb_strlen($name) > 64) {
            throw new DomainException('Give the server a name of up to 64 characters.');
        }

        if (Server::query()->where('name', $name)->where('id', '!=', $server->id ?? 0)->exists()) {
            throw new DomainException("There is already a server called $name.");
        }

        $this->requireHost($hostname, 'Hostname');

        $sshAccountId = filter_var($input['ssh_account_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if ($sshEnabled) {
            $sshPort = $this->port($input['ssh_port'] ?? 22, 'SSH port');
            $sshUsername = trim((string) ($input['ssh_username'] ?? ''));

            if ($sshAccountId !== null) {
                $chosen = Account::query()->find($sshAccountId);

                if (!$chosen instanceof Account || ($chosen->type === Account::TYPE_LOCAL && $chosen->server_id !== $server->id)) {
                    throw new DomainException('Choose an account this server can use.');
                }

                $sshUsername = $chosen->username;
            } elseif (preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $sshUsername) !== 1) {
                throw new DomainException('Enter the SSH username (letters, numbers, dots, dashes and underscores), or choose an account.');
            }
        } else {
            $sshPort = $server->ssh_port ?? 22;
            $sshUsername = '';
        }

        if (!$creating && (!$sshEnabled || $server->hostname !== $hostname || $server->ssh_port !== $sshPort)) {
            $server->ssh_host_key = null;
            $server->ssh_platform = null;
        }

        // A stored SSH password belongs to one user on one server; start setup over.
        if (!$creating && (!$sshEnabled || $server->hostname !== $hostname || $server->ssh_port !== $sshPort || $server->ssh_username !== $sshUsername)) {
            $server->ssh_auth = Server::SSH_AUTH_KEY;
            $server->ssh_password = null;
        }

        $server->name = $name;
        $server->hostname = $hostname;
        $server->ssh_enabled = $sshEnabled;
        $server->ssh_port = $sshPort;
        $server->ssh_username = $sshUsername;
        $server->ssh_password_allowed = $sshEnabled && filter_var($input['ssh_password_allowed'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$server->ssh_password_allowed) {
            $server->ssh_auth = Server::SSH_AUTH_KEY;
            $server->ssh_password = null;
        }
        $server->mysql_enabled = $mysqlEnabled;

        if ($mysqlEnabled) {
            $mysqlHost = strtolower(trim((string) ($input['mysql_host'] ?? '')));
            $mysqlUsername = trim((string) ($input['mysql_username'] ?? ''));
            $mysqlPassword = (string) ($input['mysql_password'] ?? '');

            if ($mysqlHost !== '') {
                $this->requireHost($mysqlHost, 'MySQL host');
            }

            if ($mysqlUsername === '' || mb_strlen($mysqlUsername) > 80) {
                throw new DomainException('Enter the MySQL username (up to 80 characters).');
            }

            if ($mysqlPassword === '' && $server->mysql_password === null) {
                throw new DomainException('Enter the MySQL password.');
            }

            $tls = (string) ($input['mysql_tls'] ?? Server::TLS_VERIFY);

            if (!in_array($tls, Server::TLS_MODES, true)) {
                throw new DomainException('Choose a TLS setting for MySQL.');
            }

            $ca = trim((string) ($input['mysql_tls_ca'] ?? ''));
            $server->mysql_tls = $tls;
            $server->mysql_tls_ca = $tls === Server::TLS_VERIFY && $ca !== '' ? $this->certificates->normalize($ca) : null;

            $server->mysql_host = $mysqlHost !== '' ? $mysqlHost : null;
            $server->mysql_port = $this->port($input['mysql_port'] ?? 3306, 'MySQL port');
            $server->mysql_username = $mysqlUsername;
        } else {
            // Don't keep credentials that aren't used.
            $server->mysql_host = null;
            $server->mysql_username = null;
            $server->mysql_password = null;
            $server->mysql_tls = Server::TLS_OFF;
            $server->mysql_tls_ca = null;
        }

        if (!$sshEnabled) {
            $server->ssh_account_id = null;
        }

        $server->save();

        if ($sshEnabled) {
            $this->accounts()->assign($server, $sshAccountId, $sshUsername);
        }

        // A blank password on edit keeps the stored one. Encrypted after saving
        // so a new server's context can include its id.
        if ($mysqlEnabled && $mysqlPassword !== '') {
            $server->mysql_password = $this->cipher->encrypt($mysqlPassword, $this->passwordContext($server));
            $server->save();
        }
    }

    private function port(mixed $value, string $label): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if ($port === false) {
            throw new DomainException("$label must be a number from 1 to 65535.");
        }

        return $port;
    }

    private function requireHost(string $host, string $label): void
    {
        $valid = filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if ($host === '' || !$valid || strlen($host) > 253) {
            throw new DomainException("$label must be a hostname or IP address.");
        }
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage servers.');
        }
    }

    private function sshPasswordContext(Server $server): string
    {
        return 'ssh-password:' . $server->id;
    }

    private function passwordContext(Server $server): string
    {
        return 'mysql-password:' . $server->id;
    }
}
