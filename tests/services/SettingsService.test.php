<?php

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SslMonitorService;

beforeEach(function () {
    $this->settings = new SettingsService();
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
});

test('the SSL warning period defaults to 7 days', function () {
    expect($this->settings->sslWarningDays())->toBe(7);
});

test('admins change it, and SSL monitoring uses it', function () {
    $this->settings->update($this->admin, ['ssl_warning_days' => '30']);
    $checker = (new ReflectionProperty(SslMonitorService::class, 'checker'))->getValue(new SslMonitorService());

    expect($this->settings->sslWarningDays())->toBe(30)
        ->and($checker->warningDays())->toBe(30);
});

test('invalid values are rejected and nothing changes', function (string $value) {
    try {
        $this->settings->update($this->admin, ['ssl_warning_days' => $value]);
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('1 to 365')
            ->and($this->settings->sslWarningDays())->toBe(7);

        return;
    }

    $this->fail('No DomainException');
})->with(['0', '366', 'seven', '7.5', '']);

test('only admins change settings', function () {
    $this->settings->update(new User(['role' => User::ROLE_USER]), ['ssl_warning_days' => '30']);
})->throws(AuthorizationException::class);
