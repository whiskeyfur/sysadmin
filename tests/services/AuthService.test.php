<?php

use App\Enums\LoginStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\KeyFileService;
use App\Services\TotpService;
use App\Services\UserAdminService;
use App\Services\UserKeyService;
use App\Services\VaultService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->vault = new VaultService($this->crypto);
    $this->keys = new UserKeyService($this->crypto, $this->vault);
    $this->totp = new TotpService($this->clock);
    $this->keyFiles = new KeyFileService($this->crypto);
    $this->auth = new AuthService($this->crypto, $this->vault, $this->keys, $this->totp, $this->keyFiles);
    $this->admins = new UserAdminService($this->crypto, $this->vault, $this->keys);

    $this->code = fn (string $secret) => TOTP::createFromSecret($secret)->at($this->clock->time);

    // Default admin, set up with an authenticator.
    $this->auth->ensureDefaultAdmin();
    $this->adminSecret = $this->totp->generateSecret();
    $setup = $this->auth->completeSetup('admin', 'changeme', 'admin-password', $this->adminSecret, ($this->code)($this->adminSecret));
    $this->masterKey = $setup->masterKey;
    $this->admin = User::where('username', 'admin')->firstOrFail();
    $this->clock->advance(30);
});

test('the default password stops working after setup', function () {
    expect(User::count())->toBe(1)
        ->and($this->masterKey)->not->toBeNull()
        ->and($this->auth->attempt('admin', 'changeme', '')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('the default admin is created only once', function () {
    $this->auth->ensureDefaultAdmin();

    expect(User::count())->toBe(1);
});

test('the default password only leads to setup', function () {
    User::query()->delete();
    \App\Models\Vault::query()->delete();
    $this->auth->ensureDefaultAdmin();

    expect($this->auth->attempt('admin', 'changeme', '')->status)->toBe(LoginStatus::NeedsSetup);
});

test('login needs password and authenticator code', function () {
    $result = $this->auth->attempt('admin', 'admin-password', ($this->code)($this->adminSecret));

    expect($result->status)->toBe(LoginStatus::Success)
        ->and($result->masterKey)->toBe($this->masterKey);
});

test('login fails without a valid code', function (string $code) {
    expect($this->auth->attempt('admin', 'admin-password', $code)->status)->toBe(LoginStatus::InvalidCredentials);
})->with(['', '000000']);

test('a code cannot be used twice', function () {
    $code = ($this->code)($this->adminSecret);
    $this->auth->attempt('admin', 'admin-password', $code);

    expect($this->auth->attempt('admin', 'admin-password', $code)->status)->toBe(LoginStatus::InvalidCredentials);
});

test('wrong passwords and unknown users look the same', function () {
    $code = ($this->code)($this->adminSecret);

    expect($this->auth->attempt('admin', 'wrong', $code)->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('nobody', 'wrong', $code)->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup rejects a wrong code for the new authenticator', function () {
    User::query()->delete();
    \App\Models\Vault::query()->delete();
    $this->auth->ensureDefaultAdmin();
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup('admin', 'changeme', 'new-password-1', $secret, '000000')->status)->toBe(LoginStatus::InvalidCode);
});

test('setup cannot be used to replace an enrolled authenticator', function () {
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup('admin', 'admin-password', 'other-password', $secret, ($this->code)($secret))->status)
        ->toBe(LoginStatus::InvalidCredentials);
});

test('registration creates a pending account that an admin approves', function () {
    $secret = $this->totp->generateSecret();
    $keyFile = $this->keyFiles->export($this->masterKey);

    expect($this->auth->register('alice', 'alice-password', $keyFile, $secret, ($this->code)($secret))->status)->toBe(LoginStatus::Pending);

    $this->clock->advance(30);
    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret))->status)->toBe(LoginStatus::Pending);

    $this->admins->approve($this->admin, $this->masterKey, User::where('username', 'alice')->firstOrFail());
    $this->clock->advance(30);

    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret))->status)->toBe(LoginStatus::Success);
});

test('registration rejects a wrong key file, a taken username and a wrong code', function () {
    $secret = $this->totp->generateSecret();
    $code = ($this->code)($secret);
    $goodKeyFile = $this->keyFiles->export($this->masterKey);

    expect($this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->crypto->generateKey()), $secret, $code)->status)->toBe(LoginStatus::InvalidKeyFile)
        ->and($this->auth->register('alice', 'alice-password', 'garbage', $secret, $code)->status)->toBe(LoginStatus::InvalidKeyFile)
        ->and($this->auth->register('admin', 'alice-password', $goodKeyFile, $secret, $code)->status)->toBe(LoginStatus::UsernameTaken)
        ->and($this->auth->register('alice', 'alice-password', $goodKeyFile, $secret, '000000')->status)->toBe(LoginStatus::InvalidCode)
        ->and(User::count())->toBe(1);
});

test('after rotation a user is sent to upload the new key file', function () {
    $secret = $this->totp->generateSecret();
    $this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->masterKey), $secret, ($this->code)($secret));
    $alice = User::where('username', 'alice')->firstOrFail();
    $this->admins->approve($this->admin, $this->masterKey, $alice);
    $newMasterKey = $this->keys->rotateMasterKey($this->admin, 'admin-password');

    $this->clock->advance(30);
    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret))->status)->toBe(LoginStatus::StaleKey);

    $this->clock->advance(30);
    expect($this->auth->replaceKey('alice', 'alice-password', ($this->code)($secret), $this->keyFiles->export($this->masterKey))->status)->toBe(LoginStatus::InvalidKeyFile);

    $this->clock->advance(30);
    $result = $this->auth->replaceKey('alice', 'alice-password', ($this->code)($secret), $this->keyFiles->export($newMasterKey));

    expect($result->status)->toBe(LoginStatus::Success)
        ->and($result->masterKey)->toBe($newMasterKey);
});
