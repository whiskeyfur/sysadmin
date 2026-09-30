<?php

use App\Enums\HealthStatus;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\SslCheck;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SslReportService;
use Carbon\Carbon;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->web1 = $this->servers->create($admin, ['name' => 'web-1', 'hostname' => 'web1.example.com', 'ssh_enabled' => '']);
    $this->web2 = $this->servers->create($admin, ['name' => 'web-2', 'hostname' => 'web2.example.com', 'ssh_enabled' => '']);
    $this->shop = SslCertificate::query()->create(['name' => 'Shop', 'hostnames' => 'shop.example.com']);
    $this->blog = SslCertificate::query()->create(['name' => 'Blog', 'hostnames' => 'blog.example.com']);
    $this->shop1 = SslBinding::query()->create(['certificate_id' => $this->shop->id, 'server_id' => $this->web1->id, 'port' => 443]);
    $this->shop2 = SslBinding::query()->create(['certificate_id' => $this->shop->id, 'server_id' => $this->web2->id, 'port' => 8443]);
    $this->blogDirect = SslBinding::query()->create(['certificate_id' => $this->blog->id, 'server_id' => null, 'port' => 443]);
    $this->reports = new SslReportService($this->clock);
    $this->check = fn (SslBinding $binding, int $minutesAgo, ?float $days, HealthStatus $status = HealthStatus::Ok) => SslCheck::query()->create([
        'binding_id' => $binding->id, 'server_id' => $binding->server_id ?? 0, 'host' => 'x', 'port' => $binding->port,
        'status' => $status, 'summary' => 's', 'days_left' => $days, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes($minutesAgo),
    ]);
});

test('all certificates: one line each, at the soonest-expiring place', function () {
    ($this->check)($this->shop1, 60, 40.0);
    ($this->check)($this->shop2, 60, 12.0); // web-2 still serves the old copy
    ($this->check)($this->blogDirect, 60, 80.0);
    ($this->check)($this->shop1, 5, 39.9);
    ($this->check)($this->shop2, 5, null, HealthStatus::Critical); // couldn't connect: a gap, not a zero

    $report = $this->reports->report(null, '24h');

    expect(array_keys($report['days']))->toBe(['Blog', 'Shop'])
        ->and(array_column($report['days']['Shop'], 1))->toBe([12.0, 39.9])
        ->and($report['row_total'])->toBe(5)
        // The table: each place's latest check only, soonest to expire first (a failed check first of all).
        ->and(array_map(fn ($c) => [$c->binding->label(), $c->days_left], $report['rows']))->toBe([['web-2:8443', null], ['web-1:443', 39.9], ['direct:443', 80.0]]);
});

test('one certificate: one line per place it\'s served', function () {
    ($this->check)($this->shop1, 60, 40.0);
    ($this->check)($this->shop2, 60, 12.0);
    ($this->check)($this->blogDirect, 60, 80.0);

    $report = $this->reports->report($this->shop, '24h');

    expect(array_keys($report['days']))->toBe(['web-1:443', 'web-2:8443'])
        ->and(count($report['rows']))->toBe(2);
});

test('dense data keeps the lowest days left per interval; the table shows the latest check', function () {
    for ($i = 0; $i < 2000; $i++) {
        ($this->check)($this->shop1, $i * 5, $i === 100 ? 3.0 : 50.0);
    }

    $report = $this->reports->report($this->shop, '7d');

    expect($report['bucket_minutes'])->not->toBeNull()
        ->and(min(array_column($report['days']['web-1:443'], 1)))->toBe(3.0)
        ->and($report['row_total'])->toBe(2000)
        ->and(count($report['rows']))->toBe(1)
        ->and($report['rows'][0]->checked_at->getTimestamp())->toBe(Carbon::instance($this->clock->now())->startOfSecond()->getTimestamp());
});
