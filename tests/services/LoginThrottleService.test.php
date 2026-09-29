<?php

use App\Models\LoginAttempt;
use App\Services\LoginThrottleService;

beforeEach(function () {
    $this->throttle = new LoginThrottleService($this->clock);
});

test('an address is limited after too many failures across usernames', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_IP; $i++) {
        expect($this->throttle->loginRetryAfter('10.0.0.1', "user$i"))->toBe(0);
        $this->throttle->recordLoginFailure('10.0.0.1', "user$i");
    }

    expect($this->throttle->loginRetryAfter('10.0.0.1', 'someone-new'))->toBe(LoginThrottleService::WINDOW_SECONDS)
        ->and($this->throttle->loginRetryAfter('10.0.0.2', 'someone-new'))->toBe(0);
});

test('usernames are matched case-insensitively', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->throttle->recordLoginFailure("10.0.0.$i", $i % 2 ? 'Admin' : 'admin');
    }

    expect($this->throttle->loginRetryAfter('10.9.9.9', 'ADMIN'))->toBeGreaterThan(0);
});

test('the window slides: retry-after counts from the oldest counted failure', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->throttle->recordLoginFailure("10.0.0.$i", 'admin');
        $this->clock->advance(10);
    }

    // First failure was 100 s ago, so it leaves the window in WINDOW - 100 s.
    expect($this->throttle->loginRetryAfter('10.9.9.9', 'admin'))->toBe(LoginThrottleService::WINDOW_SECONDS - 100);

    $this->clock->advance(LoginThrottleService::WINDOW_SECONDS - 100);

    expect($this->throttle->loginRetryAfter('10.9.9.9', 'admin'))->toBe(0);
});

test('a successful sign-in clears the username but not the address', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $this->throttle->recordLoginFailure('10.0.0.1', 'admin');
    }

    $this->throttle->recordLoginSuccess('admin');

    expect($this->throttle->loginRetryAfter('10.0.0.2', 'admin'))->toBe(0)
        ->and(LoginAttempt::count())->toBe(LoginThrottleService::MAX_FAILURES_PER_USERNAME);
});

test('usernames are not stored as typed', function () {
    $this->throttle->recordLoginFailure('10.0.0.1', 'my-password-typed-here');

    expect(LoginAttempt::query()->pluck('bucket')->implode(' '))->not->toContain('my-password')
        ->and(LoginAttempt::query()->pluck('bucket')->implode(' '))->not->toContain('10.0.0.1');
});

test('old attempts are purged', function () {
    $this->throttle->recordLoginFailure('10.0.0.1', 'admin');
    $this->clock->advance(LoginThrottleService::DAY_SECONDS + 1);
    $this->throttle->recordLoginFailure('10.0.0.1', 'admin');

    expect(LoginAttempt::count())->toBe(2);
});

test('a username gets at most the daily number of guesses, however slowly they come', function () {
    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME_PER_DAY; $i++) {
        expect($this->throttle->loginRetryAfter("10.0.0.$i", 'admin'))->toBe(0);
        $this->throttle->recordLoginFailure("10.0.0.$i", 'admin');
        $this->clock->advance(LoginThrottleService::WINDOW_SECONDS);
    }

    expect($this->throttle->loginRetryAfter('10.9.9.9', 'admin'))->toBeGreaterThan(0);

    $this->clock->advance(LoginThrottleService::DAY_SECONDS);

    expect($this->throttle->loginRetryAfter('10.9.9.9', 'admin'))->toBe(0);
});
