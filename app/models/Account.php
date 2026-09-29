<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tracked account: a login on a server (local) or in a directory (LDAP,
 * shared). Use AccountService to change one; it validates input and
 * encrypts the password.
 *
 * @property int $id
 * @property string $username
 * @property string $type one of the TYPE_* constants
 * @property int|null $server_id the server a local account belongs to
 * @property string|null $service for local accounts, SERVICE_SSH (system user) or SERVICE_MYSQL (database user); null for LDAP/shared
 * @property string|null $password encrypted with SecretCipher
 * @property Carbon|null $password_changed_at when the password was last reset
 * @property int|null $rotation_days change every N days; null = no rotation
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Server|null $homeServer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers
 */
class Account extends Model
{
    public const TYPE_LOCAL = 'local';

    public const TYPE_LDAP = 'ldap';

    public const TYPE_SHARED = 'shared';

    public const TYPES = [self::TYPE_LOCAL, self::TYPE_LDAP, self::TYPE_SHARED];

    public const SERVICE_SSH = 'ssh';

    public const SERVICE_MYSQL = 'mysql';

    public const SERVICES = [self::SERVICE_SSH, self::SERVICE_MYSQL];

    /**
     * @var list<string>
     */
    protected $fillable = ['username', 'type', 'server_id', 'service', 'password', 'password_changed_at', 'rotation_days', 'notes'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'password_changed_at' => 'datetime',
        'rotation_days' => 'integer',
    ];

    /**
     * The server a local account belongs to.
     *
     * @return BelongsTo<Server, $this>
     */
    public function homeServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * Servers this account is used to log into, with last_used_at.
     *
     * @return BelongsToMany<Server, $this>
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'account_server')->withPivot('last_used_at');
    }

    /**
     * Whether a server can log in with this account for $service: LDAP and
     * shared accounts anywhere, a local one only on its own server and service.
     */
    public function usableFor(Server $server, string $service): bool
    {
        return $this->type !== self::TYPE_LOCAL || ($this->server_id === $server->id && $this->localService() === $service);
    }

    /**
     * What a local account is on its server (rows from before services existed are SSH users).
     */
    public function localService(): string
    {
        return $this->service ?? self::SERVICE_SSH;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_LOCAL => $this->localService() === self::SERVICE_MYSQL ? 'Local database user' : 'Local system user',
            self::TYPE_LDAP => 'LDAP',
            self::TYPE_SHARED => 'Shared',
            default => $this->type,
        };
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }
}
