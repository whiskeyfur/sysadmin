<?php

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Services\UserAdminService;

beforeEach(function () {
    $this->admins = new UserAdminService($this->passwords);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->admin->save();
    $this->create = fn (string $name, string $role = User::ROLE_USER) => $this->admins->createUser($this->admin, $name, $role, 'hand-over-1');
});

test('admins create accounts with the one-time password they choose', function () {
    $alice = $this->admins->createUser($this->admin, 'alice', User::ROLE_USER, 'hand-over-1');

    expect($this->passwords->verify($alice, 'hand-over-1'))->toBeTrue()
        ->and($alice->must_change_password)->toBeTrue()
        ->and($alice->hasAuthenticator())->toBeFalse()
        ->and($alice->role)->toBe(User::ROLE_USER);
});

test('usernames must be unique and roles known', function (string $username, string $role) {
    ($this->create)('alice');
    $this->admins->createUser($this->admin, $username, $role, 'hand-over-1');
})->throws(DomainException::class)->with([['alice', 'user'], ['bob', 'superuser']]);

test('a one-time password that is too short is refused', function () {
    $this->admins->createUser($this->admin, 'alice', User::ROLE_USER, 'short');
})->throws(DomainException::class, 'at least');

test('a reset sets a new one-time password, removes the authenticator and ends sessions', function () {
    $alice = ($this->create)('alice');
    $alice->totp_secret = 'encrypted';
    $alice->must_change_password = false;
    $alice->save();

    $this->admins->resetPassword($this->admin, $alice, 'new-hand-over');
    $alice = $alice->fresh();

    expect($this->passwords->verify($alice, 'new-hand-over'))->toBeTrue()
        ->and($alice->must_change_password)->toBeTrue()
        ->and($alice->hasAuthenticator())->toBeFalse()
        ->and($alice->session_version)->toBe(1);
});

test('promote and demote', function () {
    $alice = ($this->create)('alice');
    $this->admins->promote($this->admin, $alice);

    expect($alice->fresh()->isAdmin())->toBeTrue();

    $this->admins->demote($this->admin, $alice);

    expect($alice->fresh()->isAdmin())->toBeFalse();
});

test('one admin is enough, but never zero', function () {
    $other = ($this->create)('other', User::ROLE_ADMIN);
    $this->admins->demote($this->admin, $other);

    expect(User::where('role', User::ROLE_ADMIN)->count())->toBe(1);
});

test('admins cannot act on their own account', function (string $method) {
    $this->admins->{$method}($this->admin, $this->admin, 'hand-over-1');
})->throws(DomainException::class, 'your own account')->with(['promote', 'demote', 'resetPassword', 'delete']);

test('deleting removes the account', function () {
    $alice = ($this->create)('alice');
    $this->admins->delete($this->admin, $alice);

    expect(User::where('username', 'alice')->exists())->toBeFalse();
});

test('non-admins cannot manage users', function (string $method) {
    $alice = ($this->create)('alice');
    $bob = ($this->create)('bob');

    $method === 'createUser'
        ? $this->admins->createUser($alice, 'carol', User::ROLE_USER, 'hand-over-1')
        : $this->admins->{$method}($alice, $bob, 'hand-over-1');
})->throws(AuthorizationException::class)->with(['createUser', 'promote', 'resetPassword', 'delete']);

test('console recovery resets an account fully, or recreates a missing admin', function () {
    $this->admin->totp_secret = 'encrypted';
    $this->admin->save();
    $version = $this->admin->session_version;

    ['user' => $reset, 'created' => $created] = $this->admins->resetFromConsole('admin', 'changeme');

    expect($created)->toBeFalse()
        ->and($this->passwords->verify($reset, 'changeme'))->toBeTrue()
        ->and($reset->must_change_password)->toBeTrue()
        ->and($reset->hasAuthenticator())->toBeFalse()
        ->and($reset->session_version)->toBe($version + 1);

    User::query()->delete();
    ['user' => $recreated, 'created' => $created] = $this->admins->resetFromConsole('admin', 'changeme');

    expect($created)->toBeTrue()
        ->and($recreated->isAdmin())->toBeTrue()
        ->and(fn () => $this->admins->resetFromConsole('nobody', 'changeme'))->toThrow(DomainException::class);
});
