<?php

use App\Enums\LoginStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\KeyFileService;
use App\Services\LoginThrottleService;
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
    $this->throttle = new LoginThrottleService($this->clock);
    $this->auth = new AuthService($this->crypto, $this->vault, $this->keys, $this->totp, $this->keyFiles, $this->throttle);
    $this->admins = new UserAdminService($this->crypto, $this->vault, $this->keys);

    $this->code = fn (string $secret) => TOTP::createFromSecret($secret)->at($this->clock->time);

    // Default admin, set up with an authenticator.
    $this->auth->ensureDefaultAdmin();
    $this->adminSecret = $this->totp->generateSecret();
    $setup = $this->auth->completeSetup('admin', 'changeme', 'admin-password', $this->adminSecret, ($this->code)($this->adminSecret), '10.0.0.1');
    $this->masterKey = $setup->masterKey;
    $this->admin = User::where('username', 'admin')->firstOrFail();
    $this->clock->advance(30);
});

test('the default password stops working after setup', function () {
    expect(User::count())->toBe(1)
        ->and($this->masterKey)->not->toBeNull()
        ->and($this->auth->attempt('admin', 'changeme', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('the default admin is created only once', function () {
    $this->auth->ensureDefaultAdmin();

    expect(User::count())->toBe(1);
});

test('the default password only leads to setup', function () {
    User::query()->delete();
    \App\Models\Vault::query()->delete();
    $this->auth->ensureDefaultAdmin();

    expect($this->auth->attempt('admin', 'changeme', '', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup);
});

test('login needs password and authenticator code', function () {
    $result = $this->auth->attempt('admin', 'admin-password', ($this->code)($this->adminSecret), '10.0.0.1');

    expect($result->status)->toBe(LoginStatus::Success)
        ->and($result->masterKey)->toBe($this->masterKey);
});

test('login fails without a valid code', function (string $code) {
    expect($this->auth->attempt('admin', 'admin-password', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
})->with(['', '000000']);

test('a code cannot be used twice', function () {
    $code = ($this->code)($this->adminSecret);
    $this->auth->attempt('admin', 'admin-password', $code, '10.0.0.1');

    expect($this->auth->attempt('admin', 'admin-password', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('wrong passwords and unknown users look the same', function () {
    $code = ($this->code)($this->adminSecret);

    expect($this->auth->attempt('admin', 'wrong', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('nobody', 'wrong', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup rejects a wrong code for the new authenticator', function () {
    User::query()->delete();
    \App\Models\Vault::query()->delete();
    $this->auth->ensureDefaultAdmin();
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup('admin', 'changeme', 'new-password-1', $secret, '000000', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode);
});

test('setup cannot be used to replace an enrolled authenticator', function () {
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup('admin', 'admin-password', 'other-password', $secret, ($this->code)($secret), '10.0.0.1')->status)
        ->toBe(LoginStatus::InvalidCredentials);
});

test('registration creates a pending account that an admin approves', function () {
    $secret = $this->totp->generateSecret();
    $keyFile = $this->keyFiles->export($this->masterKey);

    expect($this->auth->register('alice', 'alice-password', $keyFile, $secret, ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::Pending);

    $this->clock->advance(30);
    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::Pending);

    $this->admins->approve($this->admin, $this->masterKey, User::where('username', 'alice')->firstOrFail());
    $this->clock->advance(30);

    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::Success);
});

test('registration rejects a wrong key file, a taken username and a wrong code', function () {
    $secret = $this->totp->generateSecret();
    $code = ($this->code)($secret);
    $goodKeyFile = $this->keyFiles->export($this->masterKey);

    expect($this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->crypto->generateKey()), $secret, $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidKeyFile)
        ->and($this->auth->register('alice', 'alice-password', 'garbage', $secret, $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidKeyFile)
        ->and($this->auth->register('admin', 'alice-password', $goodKeyFile, $secret, $code, '10.0.0.1')->status)->toBe(LoginStatus::UsernameTaken)
        ->and($this->auth->register('alice', 'alice-password', $goodKeyFile, $secret, '000000', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and(User::count())->toBe(1);
});

test('after rotation a user is sent to upload the new key file', function () {
    $secret = $this->totp->generateSecret();
    $this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->masterKey), $secret, ($this->code)($secret), '10.0.0.1');
    $alice = User::where('username', 'alice')->firstOrFail();
    $this->admins->approve($this->admin, $this->masterKey, $alice);
    $newMasterKey = $this->keys->rotateMasterKey($this->admin, 'admin-password');

    $this->clock->advance(30);
    expect($this->auth->attempt('alice', 'alice-password', ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::StaleKey);

    $this->clock->advance(30);
    expect($this->auth->replaceKey('alice', 'alice-password', ($this->code)($secret), $this->keyFiles->export($this->masterKey), '10.0.0.1')->status)->toBe(LoginStatus::InvalidKeyFile);

    $this->clock->advance(30);
    $result = $this->auth->replaceKey('alice', 'alice-password', ($this->code)($secret), $this->keyFiles->export($newMasterKey), '10.0.0.1');

    expect($result->status)->toBe(LoginStatus::Success)
        ->and($result->masterKey)->toBe($newMasterKey);
});

test('repeated failures lock out the username, from any address', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('admin', 'wrong', '000000', "10.0.1.$i");
    }

    $result = $this->auth->attempt('admin', 'admin-password', ($this->code)($this->adminSecret), '10.0.2.1');

    expect($result->status)->toBe(LoginStatus::TooManyAttempts)
        ->and($result->retryAfter)->toBeGreaterThan(0)
        ->and($result->retryAfter)->toBeLessThanOrEqual(LoginThrottleService::WINDOW_SECONDS);
});

test('the lockout expires after the window', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('admin', 'wrong', '000000', '10.0.0.9');
    }

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS + 1);

    expect($this->auth->attempt('admin', 'admin-password', ($this->code)($this->adminSecret), '10.0.0.9')->status)->toBe(LoginStatus::Success);
});

test('limited requests look the same for unknown usernames', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('nobody', 'wrong', '000000', "10.0.1.$i");
    }

    expect($this->auth->attempt('nobody', 'wrong', '000000', '10.0.2.1')->status)->toBe(LoginStatus::TooManyAttempts);
});

test('setup and key replacement failures count toward the limit', function () {
    $secret = $this->totp->generateSecret();

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $i % 2
            ? $this->auth->completeSetup('admin', 'wrong', 'new-password-1', $secret, '000000', '10.0.0.1')
            : $this->auth->replaceKey('admin', 'wrong', '000000', 'garbage', '10.0.0.1');
    }

    expect($this->auth->attempt('admin', 'admin-password', ($this->code)($this->adminSecret), '10.0.0.1')->status)->toBe(LoginStatus::TooManyAttempts);
});

test('registrations are limited per address', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_REGISTRATIONS_PER_IP; $i++) {
        $this->auth->register("user$i", 'user-password-1', 'garbage', $this->totp->generateSecret(), '000000', '10.0.3.1');
    }

    $secret = $this->totp->generateSecret();

    expect($this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->masterKey), $secret, ($this->code)($secret), '10.0.3.1')->status)
        ->toBe(LoginStatus::TooManyAttempts)
        ->and($this->auth->register('alice', 'alice-password', $this->keyFiles->export($this->masterKey), $secret, ($this->code)($secret), '10.0.3.2')->status)
        ->toBe(LoginStatus::Pending);
});
