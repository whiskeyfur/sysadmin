<?php

namespace App\Models;

/**
 * The app's SSH keypair. Use SshKeyService, which decrypts the private key.
 *
 * @property int $id
 * @property string $public_key OpenSSH format
 * @property string $private_key encrypted with SecretCipher
 * @property string $fingerprint SHA256:...
 */
class SshKeypair extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['public_key', 'private_key', 'fingerprint'];

    /**
     * @var list<string>
     */
    protected $hidden = ['private_key'];
}
