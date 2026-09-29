<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * A monitored server. Use ServerService to create or change one; it
 * validates input and encrypts the MySQL password.
 *
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property int $ssh_port
 * @property string $ssh_username
 * @property string|null $ssh_host_key trusted host key, "type base64"
 * @property string $ssh_auth SSH_AUTH_KEY or SSH_AUTH_PASSWORD
 * @property string|null $ssh_password encrypted with SecretCipher; only for SSH_AUTH_PASSWORD
 * @property bool $ssh_password_allowed opt-in: false means never attempt a password login
 * @property bool $mysql_enabled
 * @property string|null $mysql_host null means the SSH hostname
 * @property int $mysql_port
 * @property string|null $mysql_username
 * @property string|null $mysql_password encrypted with SecretCipher
 * @property string $mysql_tls one of the TLS_* constants
 * @property string|null $mysql_tls_ca PEM CA certificate(s) for TLS_VERIFY; null = system CAs
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 * @property int $check_interval_minutes
 * @property Carbon|null $last_checked_at
 * @property string|null $last_health_status worst status of the last health check run
 */
class Server extends Model
{
    public const TLS_OFF = 'off';

    // Encrypted, but the certificate isn't checked: stops eavesdropping, not impersonation.
    public const TLS_ENCRYPT = 'encrypt';

    // Encrypted, certificate chain and hostname checked.
    public const TLS_VERIFY = 'verify';

    public const TLS_MODES = [self::TLS_VERIFY, self::TLS_ENCRYPT, self::TLS_OFF];

    public const SSH_AUTH_KEY = 'key';

    // Fallback for servers that refuse key login: the stored password is used instead.
    public const SSH_AUTH_PASSWORD = 'password';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'hostname', 'ssh_port', 'ssh_username', 'ssh_host_key', 'ssh_auth', 'ssh_password', 'ssh_password_allowed',
        'mysql_enabled', 'mysql_host', 'mysql_port', 'mysql_username', 'mysql_password', 'mysql_tls', 'mysql_tls_ca',
        'last_tested_at', 'last_test_ok', 'last_test_message',
        'check_interval_minutes', 'last_checked_at', 'last_health_status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['mysql_password', 'ssh_password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ssh_port' => 'integer',
        'ssh_password_allowed' => 'boolean',
        'mysql_enabled' => 'boolean',
        'mysql_port' => 'integer',
        'last_tested_at' => 'datetime',
        'last_test_ok' => 'boolean',
        'check_interval_minutes' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    /**
     * SSH is set up: the host key has been checked and trusted.
     */
    public function sshReady(): bool
    {
        return $this->ssh_host_key !== null;
    }

    public function mysqlHost(): string
    {
        return $this->mysql_host ?: $this->hostname;
    }
}
