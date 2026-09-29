<?php

use App\Enums\LoginStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginMethodService;
use App\Services\LoginThrottleService;
use App\Services\PasskeyService;
use App\Services\ProfileService;
use App\Services\SettingsService;
use App\Services\TotpService;
use App\Services\UserAdminService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->totp = new TotpService($this->clock);
    $this->throttle = new LoginThrottleService($this->clock);
    $this->settings = new SettingsService();
    $this->methods = new LoginMethodService($this->settings);
    $this->auth = new AuthService($this->passwords, $this->totp, $this->cipher, $this->throttle, $this->methods, new PasskeyService('test-key'));
    $this->profile = new ProfileService($this->passwords, $this->methods);
    $this->code = fn (string $secret) => TOTP::createFromSecret($secret)->at($this->clock->time);

    // The default admin, set up with an authenticator (the default: required).
    $this->auth->ensureDefaultAdmin();
    $this->adminSecret = $this->totp->generateSecret();
    $admin = $this->auth->startSetup('admin', 'changeme', '10.0.0.1')->user;
    $this->auth->enrolAuthenticator($admin, $this->adminSecret, ($this->code)($this->adminSecret));
    $this->auth->completeSetup($admin);
    $this->admin = $admin->fresh();
    $this->clock->advance(30);

    $this->levels = fn (int $password, int $authenticator, int $passkey) => $this->settings->update($this->admin, [
        SettingsService::LOGIN_PASSWORD => $password, SettingsService::LOGIN_AUTHENTICATOR => $authenticator, SettingsService::LOGIN_PASSKEY => $passkey,
    ]);
    $this->newUser = function (string $name = 'alice') {
        $user = new User(['username' => $name, 'role' => User::ROLE_USER]);
        $this->passwords->setOneTimePassword($user, 'hand-over-1');
        $user->save();

        return $user;
    };
});

test('a fresh install gets one default admin with the one-time password changeme', function () {
    User::query()->delete();
    $this->auth->ensureDefaultAdmin();
    $this->auth->ensureDefaultAdmin();

    expect(User::count())->toBe(1)
        ->and(User::first()->isAdmin())->toBeTrue()
        ->and($this->auth->startSetup('admin', 'changeme', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup)
        ->and($this->auth->attempt('admin', '', '123456', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup stores the authenticator encrypted and, once complete, discards the one-time password', function () {
    expect($this->admin->must_change_password)->toBeFalse()
        ->and($this->admin->password)->toBeNull()
        ->and($this->admin->totp_secret)->not->toContain($this->adminSecret)
        ->and($this->cipher->decrypt($this->admin->totp_secret, 'totp-secret:' . $this->admin->id))->toBe($this->adminSecret)
        ->and($this->auth->startSetup('admin', 'changeme', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('setup can\'t finish until every required method is set up, or with none at all', function () {
    $alice = ($this->newUser)();

    expect(fn () => $this->auth->completeSetup($alice))->toThrow(DomainException::class, 'authenticator app');

    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::OPTIONAL, LoginMethodService::OFF);
    expect(fn () => $this->auth->completeSetup($alice))->toThrow(DomainException::class, 'a way to sign in');

    $this->profile->setPassword($alice, 'correct horse battery', 'correct horse battery');
    $this->auth->completeSetup($alice);

    expect($alice->fresh()->must_change_password)->toBeFalse()
        ->and($alice->fresh()->password)->toBeNull();
});

test('sign-in takes any one method that is on and set up: a code, or a password', function () {
    expect($this->auth->attempt('admin', '', ($this->code)($this->adminSecret), '10.0.0.1')->status)->toBe(LoginStatus::Success);

    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::REQUIRED, LoginMethodService::OFF);
    $this->profile->setPassword($this->admin, 'correct horse battery', 'correct horse battery');

    expect($this->auth->attempt('admin', 'correct horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::Success)
        ->and($this->auth->attempt('admin', 'wrong horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('nobody', 'correct horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);

    // Turned off: the password no longer works, though it's still stored.
    ($this->levels)(LoginMethodService::OFF, LoginMethodService::REQUIRED, LoginMethodService::OFF);
    expect($this->auth->attempt('admin', 'correct horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->admin->fresh()->hasPassword())->toBeTrue();
});

test('sign-in fails without a valid code, and unknown users look the same', function (string $username, string $code) {
    expect($this->auth->attempt($username, '', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
})->with([['admin', ''], ['admin', '000000'], ['admin', 'changeme'], ['nobody', '123456']]);

test('a code cannot be used twice', function () {
    $code = ($this->code)($this->adminSecret);
    $this->auth->attempt('admin', '', $code, '10.0.0.1');

    expect($this->auth->attempt('admin', '', $code, '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('a one-time password only opens setup, and a wrong one or unknown user is refused', function () {
    ($this->newUser)();

    expect($this->auth->startSetup('alice', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::NeedsSetup)
        ->and($this->auth->startSetup('alice', 'wrong', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->startSetup('nobody', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->attempt('alice', '', '123456', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials)
        ->and($this->auth->startSetup('admin', 'changeme', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('a user in setup can\'t sign in with a method yet, even one already set up', function () {
    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::REQUIRED, LoginMethodService::OFF);
    $alice = ($this->newUser)();
    $this->profile->setPassword($alice, 'correct horse battery', 'correct horse battery');

    expect($this->auth->attempt('alice', 'correct horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('passwords: long enough, not common, not the username, typed twice the same', function (string $password, string $again, string $error) {
    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::REQUIRED, LoginMethodService::OFF);

    expect(fn () => $this->profile->setPassword($this->admin, $password, $again))->toThrow(DomainException::class, $error);
})->with([
    ['short one', 'short one', 'at least 12'],
    ['aaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaa', 'too few'],
    ['q1w2e3r4t5y6', 'q1w2e3r4t5y6', 'commonly used'],
    ['my admin password', 'my admin password', 'username'],
    ['correct horse battery', 'correct horse batterY', "don't match"],
]);

test('a method that is off can\'t be set up; a required one or the last one can\'t be removed', function () {
    expect(fn () => $this->profile->setPassword($this->admin, 'correct horse battery', 'correct horse battery'))->toThrow(DomainException::class, 'turned off')
        ->and(fn () => $this->profile->removeAuthenticator($this->admin))->toThrow(DomainException::class, 'required');

    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::OPTIONAL, LoginMethodService::OFF);
    expect(fn () => $this->profile->removeAuthenticator($this->admin))->toThrow(DomainException::class, 'last way');

    $this->profile->setPassword($this->admin, 'correct horse battery', 'correct horse battery');
    $this->profile->removeAuthenticator($this->admin);

    expect($this->admin->fresh()->hasAuthenticator())->toBeFalse();
});

test('settings refuse turning every method off, or leaving an admin without a way in', function () {
    expect(fn () => ($this->levels)(0, 0, 0))->toThrow(DomainException::class, 'at least one')
        ->and(fn () => ($this->levels)(LoginMethodService::REQUIRED, LoginMethodService::OFF, LoginMethodService::OPTIONAL))->toThrow(DomainException::class, 'admin unable to sign in');

    // Required but not set up yet is fine: they're sent to Profile after signing in another way.
    ($this->levels)(LoginMethodService::REQUIRED, LoginMethodService::OPTIONAL, LoginMethodService::OFF);
    expect($this->methods->missingRequired($this->admin))->toBe([LoginMethodService::PASSWORD])
        ->and($this->methods->usable($this->admin))->toBe([LoginMethodService::AUTHENTICATOR]);
});

test('re-confirming takes any method set up, and a code only once', function () {
    $code = ($this->code)($this->adminSecret);

    expect($this->auth->confirm($this->admin, ['code' => '000000'], '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and($this->auth->confirm($this->admin, ['code' => $code], '10.0.0.1')->status)->toBe(LoginStatus::Success)
        ->and($this->auth->confirm($this->admin, ['code' => $code], '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and($this->auth->confirm($this->admin, [], '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode);

    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::REQUIRED, LoginMethodService::OFF);
    $this->profile->setPassword($this->admin, 'correct horse battery', 'correct horse battery');

    expect($this->auth->confirm($this->admin, ['password' => 'correct horse battery'], '10.0.0.1')->status)->toBe(LoginStatus::Success);
});

test('an admin reset removes every method and signs the user out', function () {
    ($this->levels)(LoginMethodService::OPTIONAL, LoginMethodService::REQUIRED, LoginMethodService::OFF);
    $alice = ($this->newUser)();
    $secret = $this->totp->generateSecret();
    $this->auth->enrolAuthenticator($alice, $secret, ($this->code)($secret));
    $this->profile->setPassword($alice, 'correct horse battery', 'correct horse battery');
    $this->auth->completeSetup($alice);
    $alice = $alice->fresh();

    (new UserAdminService($this->passwords))->resetPassword($this->admin, $alice, 'hand-over-2');
    $alice = $alice->fresh();

    expect($this->methods->enrolled($alice))->toBe([])
        ->and($alice->must_change_password)->toBeTrue()
        ->and($alice->session_version)->toBe(1)
        ->and($this->auth->attempt('alice', 'correct horse battery', '', '10.0.0.1')->status)->toBe(LoginStatus::InvalidCredentials);
});

test('repeated failures lock out the username from any address, until the window passes', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->attempt('admin', '', '000000', "10.0.1.$i");
    }

    $result = $this->auth->attempt('admin', '', ($this->code)($this->adminSecret), '10.0.2.1');

    expect($result->status)->toBe(LoginStatus::TooManyAttempts)
        ->and($result->retryAfter)->toBeGreaterThan(0);

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS + 1);

    expect($this->auth->attempt('admin', '', ($this->code)($this->adminSecret), '10.0.2.1')->status)->toBe(LoginStatus::Success);
});

test('wrong one-time passwords count toward the limit', function () {
    ($this->newUser)();

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->auth->startSetup('alice', 'wrong', '10.0.0.1');
    }

    expect($this->auth->startSetup('alice', 'hand-over-1', '10.0.0.1')->status)->toBe(LoginStatus::TooManyAttempts);
});
