<?php

use App\Exceptions\InvalidKeyFileException;
use App\Services\KeyFileService;

test('export and parse round trip', function () {
    $service = new KeyFileService($this->crypto);
    $key = $this->crypto->generateKey();

    expect($service->parse($service->export($key)))->toBe($key);
});

test('parse rejects invalid key files', function (string $contents) {
    (new KeyFileService($this->crypto))->parse($contents);
})->throws(InvalidKeyFileException::class)->with([
    'empty' => '',
    'not json' => 'hello',
    'wrong format' => '{"format":"other","version":1,"key":"AAAA"}',
    'wrong version' => '{"format":"sys-master-key","version":2,"key":"AAAA"}',
    'short key' => '{"format":"sys-master-key","version":1,"key":"AAAA"}',
]);
