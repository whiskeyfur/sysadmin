<?php

use App\Enums\LoginStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginThrottleService;
use App\Services\PasswordService;
use App\Services\TotpService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->totp = new TotpService($this->clock);
    $this->throttle = new LoginThrottleService($this->clock);
    $this->auth = new AuthService($this->passwords, $this->totp, $this->cipher, $this->throttle);
    $this->code = fn (string $secret) => TOTP::createFromSecret($secret)->at($this->clock->time);

    // The default admin, set up with a password and an authenticator.
    $this->auth->ensureDefaultAdmin();
    $this->adminSecret = $this->totp->generateSecret();
    $this->setup = $this->auth->completeSetup('admin', 'changeme', 'admin-password-1', $this->adminSecret, ($this->code)($this->adminSecret), '10.0.0.1');
    $this->clock->advance(30);
});

test('a fresh install gets one default admin that must set up first', function () {
    User::query()->delete();
    $this->auth->ensureDefaultAdmin();
    $this->auth->ensureDefaultAdmin();

    expect(User::count())->toBe(1)
        ->and(User::first()->isAdmin())->toBeTrue()
        ->and($this->auth->attempt('admin', 'changeme', '', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup);
});

test('setup sets the password, stores the authenticator encrypted and signs in', function () {
    $admin = User::first();

    expect($this->setup->status)->toBe(LoginStatus::Success)
        ->and($admin->must_change_password)->toBeFalse()
        ->and($admin->password_changed_at)->not->toBeNull()
        ->and($admin->totp_secret)->not->toContain($this->adminSecret)
        ->and($this->cipher->decrypt($admin->totp_secret, 'totp-secret:' . $admin->id))->toBe($this->adminSecret)
        ->and($this->auth->attempt('admin', 'changeme', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('sign-in needs password and authenticator code', function () {
    expect($this->auth->attempt('admin', 'admin-password-1', ($this->code)($this->adminSecret), '10.0.0.1')->status)->toBe(LoginStatus::Success);
});

test('sign-in fails without a valid code', function (string $code) {
    expect($this->auth->attempt('admin', 'admin-password-1', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
})->with(['', '000000']);

test('a code cannot be used twice', function () {
    $code = ($this->code)($this->adminSecret);
    $this->auth->attempt('admin', 'admin-password-1', $code, '10.0.0.1');

    expect($this->auth->attempt('admin', 'admin-password-1', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('wrong passwords and unknown users look the same', function () {
    $code = ($this->code)($this->adminSecret);

    expect($this->auth->attempt('admin', 'wrong', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('nobody', 'wrong', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup rejects a wrong code, a weak password and reusing the temporary password', function () {
    $user = new User(['username' => 'alice', 'role' => User::ROLE_USER]);
    $this->passwords->setTemporaryPassword($user, 'temp-password');
    $user->save();
    $secret = $this->totp->generateSecret();
    $code = ($this->code)($secret);

    expect($this->auth->completeSetup('alice', 'temp-password', 'new-password-1', $secret, '000000', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and($this->auth->completeSetup('alice', 'temp-password', 'short', $secret, $code, '10.0.0.1')->status)->toBe(LoginStatus::PasswordRejected)
        ->and($this->auth->completeSetup('alice', 'temp-password', 'temp-password', $secret, $code, '10.0.0.1')->message)->toContain('different')
        ->and($this->auth->completeSetup('alice', 'wrong', 'new-password-1', $secret, $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup cannot replace an enrolled authenticator', function () {
    $secret = $this->totp->generateSecret();

    expect($this->auth->completeSetup('admin', 'admin-password-1', 'other-password-1', $secret, ($this->code)($secret), '10.0.0.1')->status)
        ->toBe(LoginStatus::InvalidCredentials);
});

test('passwords expire after 30 days', function () {
    $admin = User::first();

    expect($this->passwords->isExpired($admin))->toBeFalse();

    $this->clock->advance(PasswordService::MAX_AGE_DAYS * 86400);

    expect($this->passwords->isExpired($admin))->toBeTrue()
        ->and($this->auth->attempt('admin', 'admin-password-1', ($this->code)($this->adminSecret), '10.0.0.1')->status)->toBe(LoginStatus::Success);
});

test('changing the password restarts the 30 days and ends other sessions', function () {
    $this->clock->advance(PasswordService::MAX_AGE_DAYS * 86400);
    $admin = User::first();
    $version = $admin->session_version;

    expect($this->auth->changePassword($admin, 'wrong', 'admin-password-2', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->changePassword($admin, 'admin-password-1', 'admin-password-1', '10.0.0.1')->status)->toBe(LoginStatus::PasswordRejected)
        ->and($this->auth->changePassword($admin, 'admin-password-1', 'admin-password-2', '10.0.0.1')->status)->toBe(LoginStatus::Success)
        ->and($this->passwords->isExpired($admin->fresh()))->toBeFalse()
        ->and($admin->fresh()->session_version)->toBe($version + 1);
});

test('repeated failures lock out the username from any address, until the window passes', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('admin', 'wrong', '000000', "10.0.1.$i");
    }

    $result = $this->auth->attempt('admin', 'admin-password-1', ($this->code)($this->adminSecret), '10.0.2.1');

    expect($result->status)->toBe(LoginStatus::TooManyAttempts)
        ->and($result->retryAfter)->toBeGreaterThan(0);

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS + 1);

    expect($this->auth->attempt('admin', 'admin-password-1', ($this->code)($this->adminSecret), '10.0.2.1')->status)->toBe(LoginStatus::Success);
});

test('wrong current passwords when changing count toward the limit', function () {
    $admin = User::first();

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->changePassword($admin, 'wrong', 'admin-password-2', '10.0.0.1');
    }

    expect($this->auth->changePassword($admin, 'admin-password-1', 'admin-password-2', '10.0.0.1')->status)->toBe(LoginStatus::TooManyAttempts);
});
