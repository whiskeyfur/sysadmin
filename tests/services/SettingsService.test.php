<?php

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Services\HealthCheckService;
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

test('disk levels default to 85% and 95%, and the disk check uses the saved ones', function () {
    expect($this->settings->diskWarningPercent())->toBe(85)
        ->and($this->settings->diskCriticalPercent())->toBe(95);

    $this->settings->update($this->admin, ['disk_warning_percent' => '70', 'disk_critical_percent' => '80']);
    $disk = HealthCheckService::defaultSshChecks($this->settings)[0];
    $df = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 100 75 25 75% /data\n";

    expect($disk->evaluate($df)->status)->toBe(App\Enums\HealthStatus::Warning);
});

test('the disk warning level must be below the critical level', function (array $input) {
    $this->settings->update($this->admin, $input);
})->throws(DomainException::class, 'below the critical')->with([
    [['disk_warning_percent' => '95', 'disk_critical_percent' => '90']],
    [['disk_warning_percent' => '96']],
    [['disk_critical_percent' => '85']],
]);
