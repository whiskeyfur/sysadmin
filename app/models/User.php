<?php

namespace App\Models;

/**
 * A user account. Only the username and key-derivation parameters are
 * plaintext; everything else is ciphertext handled by UserKeyService.
 *
 * @property int $id
 * @property string $username
 * @property string $kdf_salt base64
 * @property int $kdf_opslimit
 * @property int $kdf_memlimit
 * @property string $user_data
 * @property string $role
 */
class User extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'username', 'kdf_salt', 'kdf_opslimit', 'kdf_memlimit', 'user_data', 'role',
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
    ];
}
