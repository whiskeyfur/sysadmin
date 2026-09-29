<?php

namespace App\Models;

/**
 * A user account. Only the username, key-derivation parameters, last TOTP
 * step and session version are plaintext; everything else is ciphertext handled by
 * UserKeyService.
 *
 * @property int $id
 * @property string $username
 * @property string $kdf_salt base64
 * @property int $kdf_opslimit
 * @property int $kdf_memlimit
 * @property string $user_data
 * @property string $role
 * @property int|null $totp_last_step
 * @property int $session_version
 */
class User extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'username', 'kdf_salt', 'kdf_opslimit', 'kdf_memlimit', 'user_data', 'role', 'totp_last_step', 'session_version',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'kdf_salt', 'user_data', 'role',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'kdf_opslimit' => 'integer',
        'kdf_memlimit' => 'integer',
        'totp_last_step' => 'integer',
        'session_version' => 'integer',
    ];
}
