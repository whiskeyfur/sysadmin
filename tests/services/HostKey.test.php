<?php

use App\DTOs\HostKey;

test('fingerprints match OpenSSH', function () {
    $blob = base64_encode(random_bytes(51));
    $key = HostKey::fromString("ssh-ed25519 $blob comment");

    expect($key->fingerprint())->toBe('SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($blob), true)), '='))
        ->and($key->toString())->toBe("ssh-ed25519 $blob");
});

test('keys are compared by blob, not by algorithm name', function () {
    $blob = base64_encode(random_bytes(40));

    expect(HostKey::fromString("rsa-sha2-512 $blob")->sameKeyAs(HostKey::fromString("ssh-rsa $blob")))->toBeTrue()
        ->and(HostKey::fromString("ssh-rsa $blob")->sameKeyAs(HostKey::fromString('ssh-rsa ' . base64_encode(random_bytes(40)))))->toBeFalse()
        ->and(HostKey::fromString("ssh-rsa $blob")->algorithms())->toContain('rsa-sha2-512');
});

test('garbage is not a host key', function () {
    HostKey::fromString('nope');
})->throws(InvalidArgumentException::class);
