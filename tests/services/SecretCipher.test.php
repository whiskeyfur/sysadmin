<?php

use App\Exceptions\DecryptionException;
use App\Services\SecretCipher;

test('encrypt and decrypt round trip with a fresh nonce each time', function () {
    $a = $this->cipher->encrypt('secret', 'ctx');

    expect($a)->not->toContain('secret')
        ->and($this->cipher->encrypt('secret', 'ctx'))->not->toBe($a)
        ->and($this->cipher->decrypt($a, 'ctx'))->toBe('secret');
});

test('decrypting fails with another context, another app key or tampering', function (string $case) {
    $encrypted = $this->cipher->encrypt('secret', 'totp-secret:1');

    match ($case) {
        'context' => $this->cipher->decrypt($encrypted, 'totp-secret:2'),
        'key' => (new SecretCipher('base64:' . base64_encode(random_bytes(32))))->decrypt($encrypted, 'totp-secret:1'),
        'tamper' => $this->cipher->decrypt(substr($encrypted, 0, -4) . 'AAAA', 'totp-secret:1'),
        'garbage' => $this->cipher->decrypt('not base64!', 'totp-secret:1'),
    };
})->throws(DecryptionException::class)->with(['context', 'key', 'tamper', 'garbage']);

test('a missing app key is refused', function () {
    new SecretCipher('');
})->throws(RuntimeException::class);
