<?php

use App\Exceptions\InvalidMasterKeyException;
use App\Exceptions\VaultStateException;
use App\Services\VaultService;

test('initialize creates a vault the master key unlocks', function () {
    $vault = new VaultService($this->crypto);
    $masterKey = $vault->initialize();

    expect($vault->isInitialized())->toBeTrue()
        ->and(strlen($vault->unwrapDataKey($masterKey)))->toBe(32);
});

test('initialize refuses to run twice', function () {
    $vault = new VaultService($this->crypto);
    $vault->initialize();
    $vault->initialize();
})->throws(VaultStateException::class);

test('unwrap fails before initialization', function () {
    (new VaultService($this->crypto))->unwrapDataKey($this->crypto->generateKey());
})->throws(VaultStateException::class);

test('unwrap rejects the wrong master key', function () {
    $vault = new VaultService($this->crypto);
    $vault->initialize();
    $vault->unwrapDataKey($this->crypto->generateKey());
})->throws(InvalidMasterKeyException::class);

test('rotation keeps the data key and retires the old master key', function () {
    $vault = new VaultService($this->crypto);
    $oldMasterKey = $vault->initialize();
    $dataKey = $vault->unwrapDataKey($oldMasterKey);
    $newMasterKey = $vault->rotateMasterKey($oldMasterKey);

    expect($newMasterKey)->not->toBe($oldMasterKey)
        ->and($vault->unwrapDataKey($newMasterKey))->toBe($dataKey)
        ->and(fn () => $vault->unwrapDataKey($oldMasterKey))->toThrow(InvalidMasterKeyException::class);
});
