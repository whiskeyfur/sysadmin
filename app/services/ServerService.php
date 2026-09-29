<?php

namespace App\Services;

use App\DTOs\HostKey;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
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
        $server->delete();
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
        $sshPort = $this->port($input['ssh_port'] ?? 22, 'SSH port');
        $sshUsername = trim((string) ($input['ssh_username'] ?? ''));
        $mysqlEnabled = filter_var($input['mysql_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $mysqlPassword = '';

        if ($name === '' || mb_strlen($name) > 64) {
            throw new DomainException('Give the server a name of up to 64 characters.');
        }

        if (Server::query()->where('name', $name)->where('id', '!=', $server->id ?? 0)->exists()) {
            throw new DomainException("There is already a server called $name.");
        }

        $this->requireHost($hostname, 'Hostname');

        if (preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $sshUsername) !== 1) {
            throw new DomainException('Enter the SSH username (letters, numbers, dots, dashes and underscores).');
        }

        if (!$creating && ($server->hostname !== $hostname || $server->ssh_port !== $sshPort)) {
            $server->ssh_host_key = null;
        }

        $server->name = $name;
        $server->hostname = $hostname;
        $server->ssh_port = $sshPort;
        $server->ssh_username = $sshUsername;
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

        $server->save();

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

    private function passwordContext(Server $server): string
    {
        return 'mysql-password:' . $server->id;
    }
}
