<?php

namespace App\Models;

/**
 * A user account. Created by admins only. Signs in with the methods an
 * admin turned on (LoginMethodService): their own password, an
 * authenticator code, or a passkey/security key. $password is only an
 * unused one-time password, which opens setup.
 *
 * @property int $id
 * @property string $username
 * @property string|null $password Argon2id hash of the one-time password, null once used
 * @property string $role "admin" or "user"
 * @property string|null $login_password Argon2id hash of the password the user chose
 * @property \Carbon\Carbon|null $password_changed_at
 * @property string|null $totp_secret encrypted with SecretCipher
 * @property int|null $totp_last_step
 * @property bool $must_change_password one-time password pending setup
 * @property int $session_version
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Passkey> $passkeys
 */
class User extends Model
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username', 'password', 'login_password', 'password_changed_at', 'role', 'totp_secret', 'totp_last_step',
        'must_change_password', 'session_version',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'login_password', 'totp_secret'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'totp_last_step' => 'integer',
        'password_changed_at' => 'datetime',
        'must_change_password' => 'boolean',
        'session_version' => 'integer',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasAuthenticator(): bool
    {
        return $this->totp_secret !== null;
    }

    public function hasPassword(): bool
    {
        return $this->login_password !== null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Passkey, $this>
     */
    public function passkeys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Passkey::class);
    }
}
