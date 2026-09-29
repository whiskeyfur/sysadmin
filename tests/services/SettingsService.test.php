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
})->throws(DomainException::class, 'must be below the disk critical level')->with([
    [['disk_warning_percent' => '95', 'disk_critical_percent' => '90']],
    [['disk_warning_percent' => '96']],
    [['disk_critical_percent' => '85']],
]);

test('MariaDB levels default to the old fixed values, and the checks use the saved ones', function () {
    $checks = collect(HealthCheckService::defaultChecks($this->settings))->keyBy(fn ($c) => $c->key());

    expect($checks['connections']->evaluate(80, 80, 100)->status)->toBe(App\Enums\HealthStatus::Warning)
        ->and($checks['server_status']->evaluate('10.11', 3599)->status)->toBe(App\Enums\HealthStatus::Warning);

    $this->settings->update($this->admin, [
        'mysql_connections_warning_percent' => '50', 'mysql_connections_critical_percent' => '70',
        'mysql_lag_warning_seconds' => '5', 'mysql_lag_critical_seconds' => '30',
        'mysql_buffer_pool_warning_percent' => '99', 'mysql_restart_warning_minutes' => '5',
    ]);
    $checks = collect(HealthCheckService::defaultChecks($this->settings))->keyBy(fn ($c) => $c->key());

    expect($checks['connections']->evaluate(70, 70, 100)->status)->toBe(App\Enums\HealthStatus::Critical)
        ->and($checks['server_status']->evaluate('10.11', 600)->status)->toBe(App\Enums\HealthStatus::Ok)
        ->and($checks['innodb_buffer_pool']->evaluate(100000, 2000)->status)->toBe(App\Enums\HealthStatus::Warning);
});

test('each MariaDB warning level must be below its critical level', function (array $input, string $message) {
    $this->settings->update($this->admin, $input);
})->throws(DomainException::class)->with([
    [['mysql_connections_warning_percent' => '96'], 'connections'],
    [['mysql_lag_warning_seconds' => '600'], 'replication lag'],
]);

test('every setting belongs to one settings page', function () {
    $listed = array_merge(...array_values(SettingsService::SECTIONS));

    expect($listed)->toEqualCanonicalizing(array_keys(SettingsService::DEFAULTS))
        ->and(count($listed))->toBe(count(array_unique($listed)));
});
