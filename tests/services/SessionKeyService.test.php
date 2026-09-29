<?php

use App\Exceptions\DecryptionException;
use App\Services\SessionKeyService;

test('seal and open round trip', function () {
    $service = new SessionKeyService($this->crypto);
    $masterKey = $this->crypto->generateKey();
    $sealed = $service->seal($masterKey);

    expect($service->open($sealed->cookieKey, $sealed->ciphertext))->toBe($masterKey)
        ->and($sealed->ciphertext)->not->toContain($this->crypto->encode($masterKey));
});

test('open fails with another session cookie key', function () {
    $service = new SessionKeyService($this->crypto);
    $sealed = $service->seal($this->crypto->generateKey());
    $other = $service->seal($this->crypto->generateKey());

    $service->open($other->cookieKey, $sealed->ciphertext);
})->throws(DecryptionException::class);

test('open fails with a malformed cookie key', function () {
    $service = new SessionKeyService($this->crypto);

    $service->open('garbage', $service->seal($this->crypto->generateKey())->ciphertext);
})->throws(DecryptionException::class);
