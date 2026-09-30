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

test('load-balanced: one row per certificate and address it came from, labelled with the host contacted', function () {
    $at = fn (SslBinding $binding, int $minutesAgo, float $days, ?string $address) => SslCheck::query()->create([
        'binding_id' => $binding->id, 'server_id' => $binding->server_id ?? 0, 'host' => 'blog.example.com', 'port' => $binding->port,
        'status' => HealthStatus::Ok, 'summary' => 's', 'days_left' => $days, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes($minutesAgo),
        'details' => ['contacted' => 'blog.example.com', 'address' => $address],
    ]);
    // Round-robin DNS: two servers behind one name, one still serving an older copy.
    $at($this->blogDirect, 30, 80.0, '203.0.113.1');
    $at($this->blogDirect, 20, 5.0, '203.0.113.2');
    $at($this->blogDirect, 10, 79.9, '203.0.113.1');
    ($this->check)($this->shop1, 5, 40.0); // recorded before addresses: the server's hostname
    // Before addresses were recorded, repeated by the newer checks from the same host: dropped.
    SslCheck::query()->create(['binding_id' => $this->blogDirect->id, 'server_id' => 0, 'host' => 'blog.example.com', 'port' => 443, 'status' => HealthStatus::Ok, 'summary' => 's',
        'days_left' => 90.0, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes(50)]);

    $rows = $this->reports->report(null, '24h')['rows'];

    expect(array_map(fn ($c) => [$c->binding->certificate->name, $c->origin(), $c->days_left], $rows))->toBe([
        ['Blog', 'blog.example.com (203.0.113.2)', 5.0],
        ['Shop', 'web1.example.com', 40.0],
        ['Blog', 'blog.example.com (203.0.113.1)', 79.9],
    ]);
});

test('a newer check that failed before reaching any address still shows, beside the older good ones', function () {
    SslCheck::query()->create(['binding_id' => $this->blogDirect->id, 'server_id' => 0, 'host' => 'blog.example.com', 'port' => 443, 'status' => HealthStatus::Ok, 'summary' => 'ok',
        'days_left' => 80.0, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes(30), 'details' => ['contacted' => 'blog.example.com', 'address' => '203.0.113.1']]);
    SslCheck::query()->create(['binding_id' => $this->blogDirect->id, 'server_id' => 0, 'host' => 'blog.example.com', 'port' => 443, 'status' => HealthStatus::Critical, 'summary' => 'unreachable',
        'days_left' => null, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes(5), 'details' => ['contacted' => 'blog.example.com', 'address' => null]]);

    expect(array_map(fn ($c) => [$c->origin(), $c->status->value], $this->reports->report(null, '24h')['rows']))->toBe([
        ['blog.example.com', 'critical'],
        ['blog.example.com (203.0.113.1)', 'ok'],
    ]);
});
