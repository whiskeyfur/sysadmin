<?php

namespace App\Models;

/**
 * A user account. Created by admins only. Signs in with username and
 * authenticator code; $password is only an unused one-time password.
 *
 * @property int $id
 * @property string $username
 * @property string|null $password Argon2id hash of the one-time password, null once used
 * @property string $role "admin" or "user"
 * @property string|null $totp_secret encrypted with SecretCipher
 * @property int|null $totp_last_step
 * @property bool $must_change_password one-time password pending setup
 * @property int $session_version
 */
class User extends Model
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username', 'password', 'role', 'totp_secret', 'totp_last_step',
        'must_change_password', 'session_version',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'totp_secret'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'totp_last_step' => 'integer',
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
}
