<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\StaleMasterKeyException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\User;
use App\Services\LoginThrottleService;
use App\Services\UserAdminService;
use App\Services\UserKeyService;
use App\Services\VaultService;

beforeEach(function () {
    $this->vault = new VaultService($this->crypto);
    $this->keys = new UserKeyService($this->crypto, $this->vault);
    $this->admins = new UserAdminService($this->crypto, $this->vault, $this->keys, new LoginThrottleService($this->clock));
    $this->masterKey = $this->keys->createFirstAdmin('admin', 'admin-pass');
    $this->admin = User::where('username', 'admin')->firstOrFail();
    $this->alice = $this->keys->register('alice', 'alice-pass', $this->masterKey, 'SECRET');

    // Approve alice plus any extra users, as [name => role]; returns fresh models by name.
    $this->approved = function (array $roles): array {
        $users = [];

        foreach ($roles as $name => $role) {
            $user = $name === 'alice' ? $this->alice : $this->keys->register($name, "$name-pass", $this->masterKey, 'SECRET');
            $this->admins->approve($this->admin, $this->masterKey, $user, $role);
            $users[$name] = $user->fresh();
        }

        return $users;
    };
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

test('promoting a user makes them an admin', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'user']);
    $this->admins->promote($this->admin, $this->masterKey, $alice);

    expect($this->admins->adminCount($this->masterKey))->toBe(2);
});

test('demoting is refused when it would leave fewer than two admins', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'admin']);

    $this->admins->demote($this->admin, $this->masterKey, $alice);
})->throws(DomainException::class, 'at least 2 admins');

test('demoting works while at least two admins remain', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'admin', 'bob' => 'admin']);
    $this->admins->demote($this->admin, $this->masterKey, $alice);

    expect($this->admins->adminCount($this->masterKey))->toBe(2);
});

test('admins cannot act on their own account', function (string $method) {
    $args = match ($method) {
        'deleteUser' => [$this->admin, $this->masterKey, $this->admin, 'admin-pass', '10.0.0.1'],
        default => [$this->admin, $this->masterKey, $this->admin],
    };

    $this->admins->{$method}(...$args);
})->throws(DomainException::class, 'your own account')->with(['promote', 'demote', 'resetPassword', 'deleteUser', 'reject']);

test('a password reset gives a temporary password, removes the authenticator and ends sessions', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'user']);
    $version = $alice->session_version;
    $temporary = $this->admins->resetPassword($this->admin, $this->masterKey, $alice);
    $unlocked = $this->keys->unlock($alice, $temporary);

    expect($temporary)->toMatch('/^[a-z2-9]{4}(-[a-z2-9]{4}){3}$/')
        ->and($unlocked->mustChangePassword)->toBeTrue()
        ->and($unlocked->hasAuthenticator())->toBeFalse()
        ->and($alice->fresh()->session_version)->toBe($version + 1);
});

test('pending registrations cannot be reset or deleted', function (string $method) {
    $args = $method === 'deleteUser'
        ? [$this->admin, $this->masterKey, $this->alice, 'admin-pass', '10.0.0.1']
        : [$this->admin, $this->masterKey, $this->alice];

    $this->admins->{$method}(...$args);
})->throws(DomainException::class)->with(['resetPassword', 'deleteUser']);

test('deleting a user rotates the master key', function () {
    ['alice' => $alice, 'bob' => $bob] = ($this->approved)(['alice' => 'user', 'bob' => 'user']);
    $newMasterKey = $this->admins->deleteUser($this->admin, $this->masterKey, $alice, 'admin-pass', '10.0.0.1');

    expect(User::where('username', 'alice')->exists())->toBeFalse()
        ->and($newMasterKey)->not->toBe($this->masterKey)
        ->and($this->keys->getMasterKeyFromUserDataField($this->admin->fresh(), 'admin-pass'))->toBe($newMasterKey)
        ->and(fn () => $this->keys->unlock($bob, 'bob-pass'))->toThrow(StaleMasterKeyException::class);
});

test('a wrong password deletes nothing and keeps the key', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'user']);

    expect(fn () => $this->admins->deleteUser($this->admin, $this->masterKey, $alice, 'wrong', '10.0.0.1'))->toThrow(InvalidCredentialsException::class)
        ->and(User::where('username', 'alice')->exists())->toBeTrue()
        ->and($this->keys->getMasterKeyFromUserDataField($this->admin, 'admin-pass'))->toBe($this->masterKey);
});

test('deleting an admin is refused when it would leave fewer than two', function () {
    ['alice' => $alice] = ($this->approved)(['alice' => 'admin']);

    $this->admins->deleteUser($this->admin, $this->masterKey, $alice, 'admin-pass', '10.0.0.1');
})->throws(DomainException::class, 'at least 2 admins');

test('manual rotation returns a new key and wrong passwords are rate limited', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        try {
            $this->admins->rotateMasterKey($this->admin, 'wrong', '10.0.0.1');
        } catch (InvalidCredentialsException) {
        }
    }

    expect(fn () => $this->admins->rotateMasterKey($this->admin, 'admin-pass', '10.0.0.1'))->toThrow(TooManyAttemptsException::class);

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS + 1);

    expect($this->admins->rotateMasterKey($this->admin, 'admin-pass', '10.0.0.1'))->not->toBe($this->masterKey);
});
