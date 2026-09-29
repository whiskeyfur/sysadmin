<?php

use App\DTOs\HostKey;
use App\Models\SshKeypair;
use App\Services\SshKeyService;
use phpseclib3\Crypt\Common\PrivateKey;

test('one Ed25519 keypair is generated on first use and reused', function () {
    $keys = new SshKeyService($this->cipher);
    $public = $keys->publicKey();

    expect($public)->toStartWith('ssh-ed25519 ')
        ->and($keys->publicKey())->toBe($public)
        ->and(SshKeypair::count())->toBe(1);
});

test('the private key is stored encrypted and matches the public key', function () {
    $keys = new SshKeyService($this->cipher);
    $keypair = $keys->keypair();
    $private = $keys->privateKey();

    expect($keypair->private_key)->not->toContain('PRIVATE KEY')
        ->and($private)->toBeInstanceOf(PrivateKey::class)
        ->and(HostKey::fromString($private->getPublicKey()->toString('OpenSSH'))->sameKeyAs(HostKey::fromString($keypair->public_key)))->toBeTrue()
        ->and($keypair->fingerprint)->toBe(HostKey::fromString($keypair->public_key)->fingerprint());
});
