<?php

use App\Exceptions\DecryptionException;

test('encrypt and decrypt round trip', function () {
    $key = $this->crypto->generateKey();
    $ciphertext = $this->crypto->encrypt('secret', $key, 'ctx');

    expect($ciphertext)->not->toContain('secret')
        ->and($this->crypto->decrypt($ciphertext, $key, 'ctx'))->toBe('secret');
});

test('each encryption uses a fresh nonce', function () {
    $key = $this->crypto->generateKey();

    expect($this->crypto->encrypt('same', $key, 'ctx'))
        ->not->toBe($this->crypto->encrypt('same', $key, 'ctx'));
});

test('decrypt fails with the wrong key', function () {
    $ciphertext = $this->crypto->encrypt('secret', $this->crypto->generateKey(), 'ctx');

    $this->crypto->decrypt($ciphertext, $this->crypto->generateKey(), 'ctx');
})->throws(DecryptionException::class);

test('decrypt fails with the wrong context', function () {
    $key = $this->crypto->generateKey();
    $ciphertext = $this->crypto->encrypt('secret', $key, 'user-data:alice');

    $this->crypto->decrypt($ciphertext, $key, 'user-data:bob');
})->throws(DecryptionException::class);

test('decrypt fails on tampered ciphertext', function () {
    $key = $this->crypto->generateKey();
    $raw = $this->crypto->decode($this->crypto->encrypt('secret', $key, 'ctx'));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

    $this->crypto->decrypt($this->crypto->encode($raw), $key, 'ctx');
})->throws(DecryptionException::class);

test('decrypt fails on malformed input', function (string $input) {
    $this->crypto->decrypt($input, $this->crypto->generateKey(), 'ctx');
})->throws(DecryptionException::class)->with(['', 'not base64!', 'c2hvcnQ=']);

test('derived keys depend on password and salt', function () {
    $salt = $this->crypto->generateSalt();
    $key = $this->crypto->deriveKey('pw', $salt, 1, 8192 * 8);

    expect(strlen($key))->toBe(32)
        ->and($this->crypto->deriveKey('pw', $salt, 1, 8192 * 8))->toBe($key)
        ->and($this->crypto->deriveKey('other', $salt, 1, 8192 * 8))->not->toBe($key)
        ->and($this->crypto->deriveKey('pw', $this->crypto->generateSalt(), 1, 8192 * 8))->not->toBe($key);
});

test('wipe nulls every secret', function () {
    $a = str_repeat('a', 32);
    $b = str_repeat('b', 32);
    $this->crypto->wipe($a, $b);

    expect($a)->toBeNull()->and($b)->toBeNull();
});
