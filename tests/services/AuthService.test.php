<?php

use App\Enums\LoginStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginThrottleService;
use App\Services\TotpService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->totp = new TotpService($this->clock);
    $this->throttle = new LoginThrottleService($this->clock);
    $this->auth = new AuthService($this->passwords, $this->totp, $this->cipher, $this->throttle);
    $this->code = fn (string $secret) => TOTP::createFromSecret($secret)->at($this->clock->time);

    // The default admin, set up with an authenticator.
    $this->auth->ensureDefaultAdmin();
    $this->adminSecret = $this->totp->generateSecret();
    $admin = $this->auth->startSetup('admin', 'changeme', '10.0.0.1')->user;
    $this->setup = $this->auth->completeSetup($admin, $admin->session_version, $this->adminSecret, ($this->code)($this->adminSecret), '10.0.0.1');
    $this->clock->advance(30);
});

test('a fresh install gets one default admin with the one-time password changeme', function () {
    User::query()->delete();
    $this->auth->ensureDefaultAdmin();
    $this->auth->ensureDefaultAdmin();

    expect(User::count())->toBe(1)
        ->and(User::first()->isAdmin())->toBeTrue()
        ->and($this->auth->startSetup('admin', 'changeme', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup)
        ->and($this->auth->attempt('admin', '123456', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup stores the authenticator encrypted, discards the one-time password and signs in', function () {
    $admin = User::first();

    expect($this->setup->status)->toBe(LoginStatus::Success)
        ->and($admin->must_change_password)->toBeFalse()
        ->and($admin->password)->toBeNull()
        ->and($admin->totp_secret)->not->toContain($this->adminSecret)
        ->and($this->cipher->decrypt($admin->totp_secret, 'totp-secret:' . $admin->id))->toBe($this->adminSecret)
        ->and($this->auth->startSetup('admin', 'changeme', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('sign-in needs only the username and an authenticator code', function () {
    expect($this->auth->attempt('admin', ($this->code)($this->adminSecret), '10.0.0.1')->status)->toBe(LoginStatus::Success);
});

test('sign-in fails without a valid code, and unknown users look the same', function (string $username, string $code) {
    expect($this->auth->attempt($username, $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
})->with([['admin', ''], ['admin', '000000'], ['admin', 'changeme'], ['nobody', '123456']]);

test('a code cannot be used twice', function () {
    $code = ($this->code)($this->adminSecret);
    $this->auth->attempt('admin', $code, '10.0.0.1');

    expect($this->auth->attempt('admin', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('a one-time password only opens setup, and a wrong one or unknown user is refused', function () {
    $user = new User(['username' => 'alice', 'role' => User::ROLE_USER]);
    $this->passwords->setOneTimePassword($user, 'hand-over-1');
    $user->save();

    expect($this->auth->startSetup('alice', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup)
        ->and($this->auth->startSetup('alice', 'wrong', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->startSetup('nobody', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('alice', '123456', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup rejects a wrong code, and a reset in between voids it', function () {
    $user = new User(['username' => 'alice', 'role' => User::ROLE_USER]);
    $this->passwords->setOneTimePassword($user, 'hand-over-1');
    $user->save();
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup($user, 0, $secret, '000000', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode);

    $user->session_version = 1;
    $user->save();

    expect($this->auth->completeSetup($user, 0, $secret, ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::SetupExpired)
        ->and($user->fresh()->hasAuthenticator())->toBeFalse();
});

test('setup cannot replace an enrolled authenticator', function () {
    $admin = User::first();
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup($admin, $admin->session_version, $secret, ($this->code)($secret), '10.0.0.1')->status)->toBe(LoginStatus::SetupExpired)
        ->and($this->cipher->decrypt($admin->fresh()->totp_secret, 'totp-secret:' . $admin->id))->toBe($this->adminSecret);
});

test('passwords left from before code-only sign-in are forgotten', function () {
    $admin = User::first();
    $admin->password = $this->passwords->hash('old-chosen-password');
    $admin->save();
    $this->auth->ensureDefaultAdmin();

    expect($admin->fresh()->password)->toBeNull();
});

test('re-confirming needs a fresh code', function () {
    $admin = User::first();
    $code = ($this->code)($this->adminSecret);

    expect($this->auth->confirm($admin, '000000', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and($this->auth->confirm($admin, $code, '10.0.0.1')->status)->toBe(LoginStatus::Success)
        ->and($this->auth->confirm($admin, $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode);
});

test('repeated failures lock out the username from any address, until the window passes', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('admin', '000000', "10.0.1.$i");
    }

    $result = $this->auth->attempt('admin', ($this->code)($this->adminSecret), '10.0.2.1');

    expect($result->status)->toBe(LoginStatus::TooManyAttempts)
        ->and($result->retryAfter)->toBeGreaterThan(0);

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS + 1);

    expect($this->auth->attempt('admin', ($this->code)($this->adminSecret), '10.0.2.1')->status)->toBe(LoginStatus::Success);
});

test('wrong one-time passwords count toward the limit', function () {
    $user = new User(['username' => 'alice', 'role' => User::ROLE_USER]);
    $this->passwords->setOneTimePassword($user, 'hand-over-1');
    $user->save();

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->startSetup('alice', 'wrong', '10.0.0.1');
    }

    expect($this->auth->startSetup('alice', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::TooManyAttempts);
});
