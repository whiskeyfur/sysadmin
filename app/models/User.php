<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * A user account. Created by admins only.
 *
 * @property int $id
 * @property string $username
 * @property string $password Argon2id hash
 * @property string $role "admin" or "user"
 * @property string|null $totp_secret encrypted with SecretCipher
 * @property int|null $totp_last_step
 * @property bool $must_change_password
 * @property Carbon|null $password_changed_at
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
        'must_change_password', 'password_changed_at', 'session_version',
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
        'password_changed_at' => 'datetime',
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
