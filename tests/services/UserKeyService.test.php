<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\DecryptionException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\InvalidMasterKeyException;
use App\Exceptions\StaleMasterKeyException;
use App\Exceptions\VaultStateException;
use App\Models\User;
use App\Services\UserKeyService;
use App\Services\VaultService;

beforeEach(function () {
    $this->vault = new VaultService($this->crypto);
    $this->keys = new UserKeyService($this->crypto, $this->vault);
    $this->masterKey = $this->keys->createFirstAdmin('admin', 'admin-pass');
    $this->admin = User::where('username', 'admin')->firstOrFail();
});

test('the first user is an admin who can unlock the master key', function () {
    $dataKey = $this->vault->unwrapDataKey($this->masterKey);

    expect($this->keys->getMasterKeyFromUserDataField($this->admin, 'admin-pass'))->toBe($this->masterKey)
        ->and($this->keys->isAdmin($this->admin, $dataKey))->toBeTrue();
});

test('only lookup and KDF fields are stored in plaintext', function () {
    $row = (array) User::query()->toBase()->where('username', 'admin')->first();
    $encodedKey = $this->crypto->encode($this->masterKey);

    expect($row['user_data'])->not->toContain($encodedKey)
        ->and($row['role'])->not->toContain('admin')
        ->and($row['kdf_opslimit'])->toEqual(1);
});

test('the first admin can only be created once', function () {
    $this->keys->createFirstAdmin('second', 'pass');
})->throws(VaultStateException::class);

test('a wrong password is rejected', function () {
    $this->keys->unlock($this->admin, 'wrong');
})->throws(InvalidCredentialsException::class);

test('registering with the key file creates a regular user', function () {
    $user = $this->keys->register('alice', 'alice-pass', $this->masterKey);
    $dataKey = $this->vault->unwrapDataKey($this->masterKey);

    expect($this->keys->getMasterKeyFromUserDataField($user, 'alice-pass'))->toBe($this->masterKey)
        ->and($this->keys->isAdmin($user, $dataKey))->toBeFalse();
});

test('registering with the wrong key is rejected', function () {
    $this->keys->register('alice', 'alice-pass', $this->crypto->generateKey());
})->throws(InvalidMasterKeyException::class);

test('changing the password re-wraps the master key', function () {
    $this->keys->changePassword($this->admin, 'admin-pass', 'new-pass');

    expect($this->keys->getMasterKeyFromUserDataField($this->admin, 'new-pass'))->toBe($this->masterKey)
        ->and(fn () => $this->keys->unlock($this->admin, 'admin-pass'))->toThrow(InvalidCredentialsException::class);
});

test('an admin reset sets a temporary password that must be changed', function () {
    $alice = $this->keys->register('alice', 'alice-pass', $this->masterKey);
    $this->keys->resetPassword($this->admin, $this->masterKey, $alice, 'temp-pass');

    expect($this->keys->unlock($alice, 'temp-pass')->mustChangePassword)->toBeTrue();

    $this->keys->changePassword($alice, 'temp-pass', 'alice-new');

    expect($this->keys->unlock($alice, 'alice-new')->mustChangePassword)->toBeFalse();
});

test('a non-admin cannot reset passwords', function () {
    $alice = $this->keys->register('alice', 'alice-pass', $this->masterKey);
    $bob = $this->keys->register('bob', 'bob-pass', $this->masterKey);

    $this->keys->resetPassword($alice, $this->masterKey, $bob, 'temp-pass');
})->throws(AuthorizationException::class);

test('a role copied from another user is rejected', function () {
    $alice = $this->keys->register('alice', 'alice-pass', $this->masterKey);
    $alice->role = $this->admin->role;

    $this->keys->isAdmin($alice, $this->vault->unwrapDataKey($this->masterKey));
})->throws(DecryptionException::class);

test('rotation makes other users stale until they upload the new key file', function () {
    $alice = $this->keys->register('alice', 'alice-pass', $this->masterKey);
    $newMasterKey = $this->keys->rotateMasterKey($this->admin, 'admin-pass');

    expect($this->keys->getMasterKeyFromUserDataField($this->admin, 'admin-pass'))->toBe($newMasterKey)
        ->and(fn () => $this->keys->unlock($alice, 'alice-pass'))->toThrow(StaleMasterKeyException::class)
        ->and(fn () => $this->keys->replaceMasterKey($alice, 'alice-pass', $this->masterKey))->toThrow(InvalidMasterKeyException::class);

    $this->keys->replaceMasterKey($alice, 'alice-pass', $newMasterKey);

    expect($this->keys->getMasterKeyFromUserDataField($alice, 'alice-pass'))->toBe($newMasterKey);
});

test('a non-admin cannot rotate the master key', function () {
    $alice = $this->keys->register('alice', 'alice-pass', $this->masterKey);

    expect(fn () => $this->keys->rotateMasterKey($alice, 'alice-pass'))->toThrow(AuthorizationException::class)
        ->and($this->keys->getMasterKeyFromUserDataField($this->admin, 'admin-pass'))->toBe($this->masterKey);
});

test('replacing the master key still requires the right password', function () {
    $this->keys->replaceMasterKey($this->admin, 'wrong', $this->masterKey);
})->throws(InvalidCredentialsException::class);
