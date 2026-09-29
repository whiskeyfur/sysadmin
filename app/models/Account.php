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
 * @property string|null $service SERVICE_SSH (SSH logins) or SERVICE_MYSQL (database logins); SSH and database accounts never mix. Null only on rows from before, see serviceName()
 * @property string|null $password encrypted with SecretCipher
 * @property Carbon|null $password_changed_at when the password was last reset
 * @property int|null $rotation_days change every N days; null = no rotation
 * @property string|null $notes
 * @property string|null $origin one of the ORIGIN_* constants; null for accounts from before origins were recorded
 * @property int|null $origin_server_id the server it was created from or imported from
 * @property int|null $origin_user_id the admin who added or imported it
 * @property string|null $origin_detail e.g. the MariaDB account it was imported from
 * @property Carbon $created_at
 * @property-read Server|null $homeServer
 * @property-read Server|null $originServer
 * @property-read User|null $originUser
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

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_SERVER = 'server';

    public const ORIGIN_IMPORT = 'import';

    /**
     * @var list<string>
     */
    protected $fillable = ['origin', 'origin_server_id', 'origin_user_id', 'origin_detail', 'username', 'type', 'server_id', 'service', 'password', 'password_changed_at', 'rotation_days', 'notes'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'origin_server_id' => 'integer',
        'origin_user_id' => 'integer',
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
     * Whether a server can log in with this account for $service: accounts
     * of that service only; LDAP and shared ones on any server, a local one
     * only on its own. (LDAP/shared rows from before services existed fit
     * either until AccountService::syncServers() assigns them.)
     */
    public function usableFor(Server $server, string $service): bool
    {
        if (!$this->canLogIn()) {
            return false;
        }

        if ($this->type !== self::TYPE_LOCAL) {
            return $this->service === null || $this->service === $service;
        }

        return $this->server_id === $server->id && $this->serviceName() === $service;
    }

    /**
     * An imported account can't be used to log into a server until an admin
     * records its password: the import brings the name, never a password.
     */
    public function canLogIn(): bool
    {
        return $this->origin !== self::ORIGIN_IMPORT || $this->password !== null;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function originServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'origin_server_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function originUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'origin_user_id');
    }

    /**
     * Where the account came from, in a word or two (the list's Origin column).
     */
    public function originShort(): string
    {
        return match ($this->origin) {
            self::ORIGIN_IMPORT => 'Imported from ' . ($this->originServer->name ?? 'a deleted server'),
            self::ORIGIN_SERVER => 'Server settings',
            self::ORIGIN_MANUAL => 'Added by hand',
            default => 'Not recorded',
        };
    }

    /**
     * Where the account came from, for people.
     */
    public function originLabel(): string
    {
        $server = $this->originServer->name ?? 'a server since deleted';
        $user = $this->originUser->username ?? null;
        $when = \App\Utils\LocalTime::format($this->created_at, 'Y-m-d');

        return match ($this->origin) {
            self::ORIGIN_IMPORT => "Imported from $server's MariaDB users" . ($this->origin_detail ? " ({$this->origin_detail})" : '') . ($user ? " by $user" : '') . " on $when",
            self::ORIGIN_SERVER => "Created from $server's login settings on $when",
            self::ORIGIN_MANUAL => 'Added' . ($user ? " by $user" : '') . " on $when",
            default => 'Not recorded (tracked before origins were)',
        };
    }

    /**
     * SSH or database account (rows from before services existed count as SSH).
     */
    public function serviceName(): string
    {
        return $this->service ?? self::SERVICE_SSH;
    }

    /**
     * What a local account is on its server; see serviceName().
     */
    public function localService(): string
    {
        return $this->serviceName();
    }

    public function serviceLabel(): string
    {
        return $this->serviceName() === self::SERVICE_MYSQL ? 'Database' : 'SSH';
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
