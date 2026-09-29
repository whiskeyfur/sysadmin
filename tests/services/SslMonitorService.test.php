<?php

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\AuthorizationException;
use App\Models\SslCheck;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SslCheckService;
use App\Services\SslMonitorService;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);

    // Stand-in checker: status per host, records calls.
    $this->checker = new class () extends SslCheckService {
        public array $statuses = [];
        public array $calls = [];

        public function __construct()
        {
        }

        public function check(string $host, int $port = 443): CheckResult
        {
            $this->calls[] = "$host:$port";
            $status = $this->statuses[$host] ?? HealthStatus::Ok;

            return new CheckResult("ssl:$host:$port", $host, $status, "$host is {$status->value}", $status === HealthStatus::Ok ? 60.0 : 3.0, 'days', ['valid_to' => '2026-12-01 00:00 UTC']);
        }
    };

    $this->monitor = new SslMonitorService($this->checker, $this->clock);
    $this->makeServer = fn (string $name, string $hosts) => $this->servers->create($this->admin, [
        'name' => $name, 'hostname' => "$name.example.com", 'ssh_enabled' => '', 'ssl_enabled' => '1', 'ssl_hosts' => $hosts,
    ]);
});

test('each configured host is checked and stored; the worst status is recorded', function () {
    $server = ($this->makeServer)('web', "a.example.com\nb.example.com:8443");
    $this->checker->statuses['b.example.com'] = HealthStatus::Warning;
    $this->monitor->check($this->admin, $server);
    $server = $server->fresh();

    expect($this->checker->calls)->toBe(['a.example.com:443', 'b.example.com:8443'])
        ->and(SslCheck::count())->toBe(2)
        ->and($server->last_ssl_status)->toBe('warning')
        ->and(array_map(fn ($c) => $c->label(), $this->monitor->latest($server)))->toBe(['b.example.com:8443', 'a.example.com']);
});

test('an empty host list checks the server hostname', function () {
    $server = ($this->makeServer)('web', '');
    $this->monitor->check($this->admin, $server);

    expect($this->checker->calls)->toBe(['web.example.com:443']);
});

test('the SSL page lists every server, worst first', function () {
    $this->checker->statuses['bad.example.com'] = HealthStatus::Critical;
    ($this->makeServer)('one', 'good.example.com');
    ($this->makeServer)('two', 'bad.example.com');

    expect($this->monitor->checkAll($this->admin))->toBe(2)
        ->and(array_map(fn ($c) => $c->host, $this->monitor->latest()))->toBe(['bad.example.com', 'good.example.com'])
        ->and($this->monitor->latest()[0]->server->name)->toBe('two');
});

test('history keeps recent runs per host and prunes after 30 days', function () {
    $server = ($this->makeServer)('web', 'a.example.com');

    foreach (range(1, 3) as $run) {
        $this->monitor->check($this->admin, $server->fresh());
        $this->clock->advance(3600);
    }

    expect($this->monitor->history($server->fresh())['a.example.com:443'])->toHaveCount(3);

    $this->clock->advance(SslMonitorService::RETENTION_DAYS * 86400);
    $this->monitor->check($this->admin, $server->fresh());

    expect(SslCheck::count())->toBe(1);
});

test('only admins run SSL checks, and deleting a server deletes its history', function () {
    $server = ($this->makeServer)('web', 'a.example.com');

    expect(fn () => $this->monitor->check(new User(['role' => User::ROLE_USER]), $server))->toThrow(AuthorizationException::class);

    $this->monitor->check($this->admin, $server);
    $this->servers->delete($this->admin, $server);

    expect(SslCheck::count())->toBe(0);
});
