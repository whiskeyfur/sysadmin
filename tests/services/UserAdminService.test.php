<?php

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Services\UserAdminService;
use App\Services\UserKeyService;
use App\Services\VaultService;

beforeEach(function () {
    $this->vault = new VaultService($this->crypto);
    $this->keys = new UserKeyService($this->crypto, $this->vault);
    $this->admins = new UserAdminService($this->crypto, $this->vault, $this->keys);
    $this->masterKey = $this->keys->createFirstAdmin('admin', 'admin-pass');
    $this->admin = User::where('username', 'admin')->firstOrFail();
    $this->alice = $this->keys->register('alice', 'alice-pass', $this->masterKey, 'SECRET');
});

test('admins see every user with their role', function () {
    $rows = $this->admins->listUsers($this->admin, $this->masterKey);

    expect(array_map(fn ($row) => [$row['user']->username, $row['role']], $rows))
        ->toBe([['admin', 'admin'], ['alice', 'pending']]);
});

test('approving as admin counts toward the two-admin rule', function () {
    expect($this->admins->needsSecondAdmin($this->masterKey))->toBeTrue();

    $this->admins->approve($this->admin, $this->masterKey, $this->alice, UserKeyService::ROLE_ADMIN);

    expect($this->admins->adminCount($this->masterKey))->toBe(2)
        ->and($this->admins->needsSecondAdmin($this->masterKey))->toBeFalse();
});

test('rejecting deletes the pending registration', function () {
    $this->admins->reject($this->admin, $this->masterKey, $this->alice);

    expect(User::where('username', 'alice')->exists())->toBeFalse();
});

test('only pending users can be approved or rejected', function () {
    $this->admins->approve($this->admin, $this->masterKey, $this->alice);
    $this->admins->reject($this->admin, $this->masterKey, $this->alice);
})->throws(DomainException::class);

test('approval cannot grant the pending role or unknown roles', function () {
    $this->admins->approve($this->admin, $this->masterKey, $this->alice, 'pending');
})->throws(DomainException::class);

test('non-admins cannot manage users', function () {
    $this->admins->approve($this->admin, $this->masterKey, $this->alice);
    $bob = $this->keys->register('bob', 'bob-pass', $this->masterKey, 'SECRET');

    $this->admins->approve($this->alice, $this->masterKey, $bob);
})->throws(AuthorizationException::class);
