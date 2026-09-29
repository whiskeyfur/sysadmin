<?php

namespace App\Models;

use App\Enums\ServerPlatform;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monitored server. Use ServerService to create or change one; it
 * validates input and encrypts the MySQL password.
 *
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property bool $ssh_enabled
 * @property int $ssh_port
 * @property int|null $ssh_account_id the Account used for SSH
 * @property string $ssh_username '' when SSH is off
 * @property string|null $ssh_host_key trusted host key, "type base64"
 * @property string $ssh_auth SSH_AUTH_KEY or SSH_AUTH_PASSWORD
 * @property string|null $ssh_platform a ServerPlatform value, from the SSH banner
 * @property string|null $ssh_password legacy: moved to the server's Account by AccountService::syncServers()
 * @property bool $ssh_password_allowed opt-in: false means never attempt a password login
 * @property bool $mysql_enabled
 * @property string|null $mysql_host null means the SSH hostname
 * @property int $mysql_port
 * @property int|null $mysql_account_id the Account used for the database login
 * @property string|null $mysql_username
 * @property string|null $mysql_password legacy (before accounts), encrypted with SecretCipher
 * @property string $mysql_tls one of the TLS_* constants
 * @property string|null $mysql_tls_ca PEM CA certificate(s) for TLS_VERIFY; null = system CAs
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 * @property int $check_interval_minutes
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $log_imported_at when the MariaDB log was last imported
 * @property string|null $last_health_status worst status of the last health check run
 * @property bool $ssl_enabled
 * @property string|null $ssl_hosts one per line, "host" or "host:port"
 * @property Carbon|null $last_ssl_checked_at
 * @property string|null $last_ssl_status worst status of the last SSL check
 * @property-read Account|null $sshAccount
 * @property-read Account|null $mysqlAccount
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
        'name', 'hostname', 'ssh_enabled', 'ssh_port', 'ssh_account_id', 'ssh_username', 'ssh_host_key', 'ssh_auth', 'ssh_platform', 'ssh_password', 'ssh_password_allowed',
        'mysql_enabled', 'mysql_host', 'mysql_port', 'mysql_account_id', 'mysql_username', 'mysql_password', 'mysql_tls', 'mysql_tls_ca',
        'last_tested_at', 'last_test_ok', 'last_test_message',
        'check_interval_minutes', 'last_checked_at', 'log_imported_at', 'last_health_status',
        'ssl_enabled', 'ssl_hosts', 'last_ssl_checked_at', 'last_ssl_status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['mysql_password', 'ssh_password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ssh_enabled' => 'boolean',
        'ssh_port' => 'integer',
        'ssh_account_id' => 'integer',
        'mysql_account_id' => 'integer',
        'ssl_enabled' => 'boolean',
        'last_ssl_checked_at' => 'datetime',
        'ssh_password_allowed' => 'boolean',
        'mysql_enabled' => 'boolean',
        'mysql_port' => 'integer',
        'last_tested_at' => 'datetime',
        'last_test_ok' => 'boolean',
        'check_interval_minutes' => 'integer',
        'last_checked_at' => 'datetime',
        'log_imported_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function sshAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ssh_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function mysqlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'mysql_account_id');
    }

    public function platform(): ServerPlatform
    {
        return ServerPlatform::tryFrom((string) $this->ssh_platform) ?? ServerPlatform::Unknown;
    }

    /**
     * SSH is set up: the host key has been checked and trusted.
     */
    public function sshReady(): bool
    {
        return $this->ssh_enabled && $this->ssh_host_key !== null;
    }

    /**
     * The HTTPS endpoints to monitor: the configured hosts, or the server's
     * hostname on 443 when the list is empty.
     *
     * @return list<array{host: string, port: int}>
     */
    public function sslTargets(): array
    {
        if (!$this->ssl_enabled) {
            return [];
        }

        $targets = [];

        foreach (preg_split('/\R/', trim((string) $this->ssl_hosts)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$host, $port] = array_pad(explode(':', trim($line), 2), 2, '443');
            $targets[] = ['host' => $host, 'port' => (int) $port];
        }

        return $targets !== [] ? $targets : [['host' => $this->hostname, 'port' => 443]];
    }

    public function mysqlHost(): string
    {
        return $this->mysql_host ?: $this->hostname;
    }
}
