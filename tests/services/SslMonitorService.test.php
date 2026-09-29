<?php

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\SslCheck;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SslCheckService;
use App\Services\SslMonitorService;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);

    // Stand-in checker: records (host, port, connectTo, expected) and returns a status per connectTo.
    $this->checker = new class () extends SslCheckService {
        public array $statuses = [];
        public array $calls = [];

        public function __construct()
        {
        }

        public function check(string $host, int $port = 443, ?string $connectTo = null, array $expectedNames = []): CheckResult
        {
            $this->calls[] = [$host, $port, $connectTo, $expectedNames];
            $status = $this->statuses[$connectTo ?? 'dns'] ?? HealthStatus::Ok;

            return new CheckResult("ssl:$host:$port", $host, $status, "$host via " . ($connectTo ?? 'dns') . " is {$status->value}", 60.0, 'days', ['names' => $expectedNames]);
        }
    };

    $this->ssl = new SslMonitorService($this->checker, $this->clock);
    $this->server = fn (string $name, string $host) => $this->servers->create($this->admin, ['name' => $name, 'hostname' => $host, 'ssh_enabled' => '']);
    $this->certificate = fn (array $input = []) => $this->ssl->createCertificate($this->admin, $input + ['name' => 'Shop 2026', 'hostnames' => "shop.example.com\nwww.shop.example.com"]);
});

test('a certificate has a name distinct from its hostnames', function () {
    $certificate = ($this->certificate)(['hostnames' => "https://Shop.Example.com/cart\n*.shop.example.com, www.shop.example.com"]);

    expect($certificate->name)->toBe('Shop 2026')
        ->and($certificate->hostnameList())->toBe(['shop.example.com', '*.shop.example.com', 'www.shop.example.com'])
        ->and($certificate->primaryHostname())->toBe('shop.example.com');
});

test('certificate input is validated', function (array $input, string $message) {
    ($this->certificate)($input);
})->throws(DomainException::class)->with([
    [['name' => ''], 'name'],
    [['hostnames' => ''], 'hostnames'],
    [['hostnames' => '*.example.com'], 'wildcard'],
    [['hostnames' => 'not a host!'], 'hostname'],
]);

test('each server serving the certificate is checked at its own address and port, with SNI', function () {
    $web1 = ($this->server)('web-1', '10.0.0.11');
    $web2 = ($this->server)('web-2', '10.0.0.12');
    $certificate = ($this->certificate)();
    $this->ssl->addBinding($this->admin, $certificate, $web1, 443);
    $this->ssl->addBinding($this->admin, $certificate, $web2, 8443);
    $this->ssl->addBinding($this->admin, $certificate, null, 443);

    expect($this->ssl->checkCertificate($this->admin, $certificate))->toBe(3)
        ->and($this->checker->calls)->toBe([
            ['shop.example.com', 443, '10.0.0.11', ['shop.example.com', 'www.shop.example.com']],
            ['shop.example.com', 8443, '10.0.0.12', ['shop.example.com', 'www.shop.example.com']],
            ['shop.example.com', 443, null, ['shop.example.com', 'www.shop.example.com']],
        ]);
});

test('the same hostnames can be served by several servers, and one server can serve several certificates on different ports', function () {
    $web = ($this->server)('web', '10.0.0.11');
    $shop = ($this->certificate)();
    $api = ($this->certificate)(['name' => 'API', 'hostnames' => 'api.example.com']);
    $this->ssl->addBinding($this->admin, $shop, $web, 443);
    $this->ssl->addBinding($this->admin, $api, $web, 9443);

    expect(array_map(fn (SslBinding $b) => $b->certificate->name . ':' . $b->port, $this->ssl->bindingsFor($web)))->toBe(['Shop 2026:443', 'API:9443'])
        ->and(fn () => $this->ssl->addBinding($this->admin, $shop, $web, 443))->toThrow(DomainException::class, 'already listed');
});

test('results roll up to the binding, the certificate and the server; the worst wins', function () {
    $web1 = ($this->server)('web-1', '10.0.0.11');
    $web2 = ($this->server)('web-2', '10.0.0.12');
    $certificate = ($this->certificate)();
    $this->ssl->addBinding($this->admin, $certificate, $web1, 443);
    $this->ssl->addBinding($this->admin, $certificate, $web2, 443);
    $this->checker->statuses['10.0.0.12'] = HealthStatus::Critical;

    $this->ssl->checkAll($this->admin);

    expect($certificate->fresh()->last_status)->toBe('critical')
        ->and($web1->fresh()->last_ssl_status)->toBe('ok')
        ->and($web2->fresh()->last_ssl_status)->toBe('critical')
        ->and(SslCheck::count())->toBe(2)
        ->and($this->ssl->certificates()[0]->bindings->pluck('last_status')->all())->toBe(['ok', 'critical']);
});

test('history is kept per binding and pruned after 30 days', function () {
    $web = ($this->server)('web', '10.0.0.11');
    $certificate = ($this->certificate)();
    $binding = $this->ssl->addBinding($this->admin, $certificate, $web, 443);

    foreach (range(1, 3) as $run) {
        $this->ssl->checkCertificate($this->admin, $certificate);
        $this->clock->advance(3600);
    }

    expect($this->ssl->history($binding))->toHaveCount(3);

    $this->clock->advance(SslMonitorService::RETENTION_DAYS * 86400);
    $this->ssl->checkCertificate($this->admin, $certificate);

    expect(SslCheck::count())->toBe(1);
});

test('removing a binding or deleting the certificate removes its results', function () {
    $web = ($this->server)('web', '10.0.0.11');
    $certificate = ($this->certificate)();
    $binding = $this->ssl->addBinding($this->admin, $certificate, $web, 443);
    $this->ssl->checkCertificate($this->admin, $certificate);
    $this->ssl->removeBinding($this->admin, $binding->fresh(['certificate', 'server']));

    expect(SslCheck::count())->toBe(0)
        ->and($web->fresh()->last_ssl_status)->toBeNull();

    $this->ssl->addBinding($this->admin, $certificate, $web, 443);
    $this->ssl->checkCertificate($this->admin, $certificate);
    $this->ssl->deleteCertificate($this->admin, $certificate);

    expect(SslCertificate::count() + SslBinding::count() + SslCheck::count())->toBe(0);
});

test('per-server SSL hosts from before certificates existed are converted once', function () {
    $web = ($this->server)('web', '10.0.0.11');
    $web->ssl_enabled = true;
    $web->ssl_hosts = "shop.example.com\napi.example.com:8443";
    $web->save();

    $this->ssl->convertLegacy();
    $this->ssl->convertLegacy();

    expect(SslCertificate::query()->pluck('name')->sort()->values()->all())->toBe(['api.example.com', 'shop.example.com'])
        ->and(array_map(fn ($b) => $b->certificate->name . ':' . $b->port, $this->ssl->bindingsFor($web)))->toBe(['shop.example.com:443', 'api.example.com:8443'])
        ->and($web->fresh()->ssl_enabled)->toBeFalse();
});

test('only admins manage certificates or run checks', function () {
    $user = new User(['role' => User::ROLE_USER]);
    $certificate = ($this->certificate)();

    expect(fn () => $this->ssl->createCertificate($user, ['name' => 'x', 'hostnames' => 'x.example.com']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ssl->addBinding($user, $certificate, null, 443))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ssl->checkAll($user))->toThrow(AuthorizationException::class);
});
