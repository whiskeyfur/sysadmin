<?php

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Services\UserAdminService;

beforeEach(function () {
    $this->admins = new UserAdminService($this->passwords);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->passwords->setChosenPassword($this->admin, 'admin-password-1');
    $this->admin->save();
    $this->create = fn (string $name, string $role = User::ROLE_USER) => $this->admins->createUser($this->admin, $name, $role)['user'];
});

test('admins create accounts with a temporary password that must be changed', function () {
    ['user' => $alice, 'temporaryPassword' => $temporary] = $this->admins->createUser($this->admin, 'alice', User::ROLE_USER);

    expect($temporary)->toMatch('/^[a-z2-9]{4}(-[a-z2-9]{4}){3}$/')
        ->and($this->passwords->verify($alice, $temporary))->toBeTrue()
        ->and($alice->must_change_password)->toBeTrue()
        ->and($alice->hasAuthenticator())->toBeFalse()
        ->and($alice->role)->toBe(User::ROLE_USER);
});

test('usernames must be unique and roles known', function (string $username, string $role) {
    ($this->create)('alice');
    $this->admins->createUser($this->admin, $username, $role);
})->throws(DomainException::class)->with([['alice', 'user'], ['bob', 'superuser']]);

test('a password reset issues a temporary password, removes the authenticator and ends sessions', function () {
    $alice = ($this->create)('alice');
    $alice->totp_secret = 'encrypted';
    $alice->must_change_password = false;
    $alice->save();

    $temporary = $this->admins->resetPassword($this->admin, $alice);
    $alice = $alice->fresh();

    expect($this->passwords->verify($alice, $temporary))->toBeTrue()
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
    $this->admins->{$method}($this->admin, $this->admin);
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
        ? $this->admins->createUser($alice, 'carol', User::ROLE_USER)
        : $this->admins->{$method}($alice, $bob);
})->throws(AuthorizationException::class)->with(['createUser', 'promote', 'resetPassword', 'delete']);
