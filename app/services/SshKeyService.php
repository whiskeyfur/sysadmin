<?php

namespace App\Services;

use App\Models\SshKeypair;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * The app's own SSH identity: one Ed25519 keypair, generated on first use.
 * Admins add the public key to each server's authorized_keys. The private
 * key is stored encrypted and never leaves the app.
 */
class SshKeyService
{
    public function __construct(private readonly SecretCipher $cipher = new SecretCipher())
    {
    }

    public function keypair(): SshKeypair
    {
        $existing = SshKeypair::query()->latest('id')->first();

        return $existing instanceof SshKeypair ? $existing : $this->generate();
    }

    /**
     * The public key line for authorized_keys.
     */
    public function publicKey(): string
    {
        return $this->keypair()->public_key;
    }

    public function privateKey(): PrivateKey
    {
        $keypair = $this->keypair();
        $pem = $this->cipher->decrypt($keypair->private_key, $this->context($keypair));
        $key = PublicKeyLoader::load($pem);

        if (!$key instanceof PrivateKey) {
            throw new \RuntimeException('The stored SSH key is not a private key.');
        }

        return $key;
    }

    private function generate(): SshKeypair
    {
        $key = EC::createKey('Ed25519');
        $public = $key->getPublicKey();
        $host = parse_url((string) _env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost';

        $keypair = new SshKeypair([
            'public_key' => $public->toString('OpenSSH', ['comment' => "sys@$host"]),
            'fingerprint' => 'SHA256:' . $public->getFingerprint('sha256'),
            'private_key' => '',
        ]);
        $keypair->save();

        // Encrypt after saving so the context can include the row id.
        $keypair->private_key = $this->cipher->encrypt($key->toString('OpenSSH'), $this->context($keypair));
        $keypair->save();

        return $keypair;
    }

    private function context(SshKeypair $keypair): string
    {
        return 'ssh-private-key:' . $keypair->id;
    }
}
