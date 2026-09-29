<?php

use App\Services\TotpService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->totp = new TotpService($this->clock);
    $this->secret = $this->totp->generateSecret();
    $this->codeAt = fn (int $time) => TOTP::createFromSecret($this->secret)->at($time);
});

test('secrets are 160-bit base32, the Google Authenticator default', function () {
    expect($this->totp->isValidSecret($this->secret))->toBeTrue()
        ->and($this->totp->isValidSecret('not-a-secret'))->toBeFalse();
});

test('the provisioning URI uses the app defaults', function () {
    $uri = $this->totp->provisioningUri($this->secret, 'alice');

    expect($uri)->toStartWith('otpauth://totp/sys%3Aalice?')
        ->and($uri)->toContain('secret=' . $this->secret)
        ->and($uri)->toContain('issuer=sys')
        ->and($this->totp->qrCode($uri))->toStartWith('data:image/svg+xml;base64,');
});

test('the current code is accepted and returns its time step', function () {
    expect($this->totp->verify($this->secret, ($this->codeAt)($this->clock->time)))->toBe(intdiv($this->clock->time, 30));
});

test('codes one period either side are accepted for clock drift', function () {
    expect($this->totp->verify($this->secret, ($this->codeAt)($this->clock->time - 30)))->not->toBeNull()
        ->and($this->totp->verify($this->secret, ($this->codeAt)($this->clock->time + 30)))->not->toBeNull()
        ->and($this->totp->verify($this->secret, ($this->codeAt)($this->clock->time - 90)))->toBeNull();
});

test('a code cannot be replayed', function () {
    $code = ($this->codeAt)($this->clock->time);
    $step = $this->totp->verify($this->secret, $code);

    expect($this->totp->verify($this->secret, $code, $step))->toBeNull();
});

test('malformed codes are rejected', function (string $code) {
    expect($this->totp->verify($this->secret, $code))->toBeNull();
})->with(['', '12345', '1234567', 'abcdef']);
