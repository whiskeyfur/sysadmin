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
        /** @var list<string> names the served certificate has beyond the listed ones */
        public array $extraNames = [];
        public array $calls = [];

        public function __construct()
        {
        }

        public function check(string $host, int $port = 443, ?string $connectTo = null, array $expectedNames = []): CheckResult
        {
            $this->calls[] = [$host, $port, $connectTo, $expectedNames];
            $status = $this->statuses[$connectTo ?? 'dns'] ?? HealthStatus::Ok;

            return new CheckResult("ssl:$host:$port", $host, $status, "$host via " . ($connectTo ?? 'dns') . " is {$status->value}", 60.0, 'days', ['names' => [...$expectedNames, ...$this->extraNames]]);
        }

        /** @var list<string>|null what the served certificate lists */
        public ?array $served = null;
        public array $nameCalls = [];

        public function certificateNames(string $host, int $port = 443, ?string $connectTo = null): ?array
        {
            $this->nameCalls[] = [$host, $port, $connectTo];

            return $this->served;
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
    $own = fn ($query) => $query->where('certificate_id', $certificate->id)->count();

    // Entries its names got stay: they're checked on their own.
    expect(SslCertificate::query()->find($certificate->id))->toBeNull()
        ->and($own(SslBinding::query()))->toBe(0)
        ->and(SslCheck::query()->whereIn('binding_id', [$binding->id])->count())->toBe(0);
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

test('only admins manage certificates; anyone can run checks, non-admins once per cooldown', function () {
    $user = new User(['role' => User::ROLE_USER]);
    $certificate = ($this->certificate)(['port' => '443']);

    expect(fn () => $this->ssl->createCertificate($user, ['name' => 'x', 'hostnames' => 'x.example.com']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ssl->addBinding($user, $certificate, null, 8443))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->ssl->deleteCertificate($user, $certificate))->toThrow(AuthorizationException::class)
        ->and($this->ssl->checkAll($user))->toBe(1)
        ->and(fn () => $this->ssl->checkCertificate($user, $certificate->fresh()))->toThrow(DomainException::class, 'Try again in')
        ->and($this->ssl->checkCertificate($this->admin, $certificate->fresh()))->toBe(1);

    $this->clock->advance(App\Services\CheckCooldown::SECONDS);

    expect($this->ssl->checkCertificate($user, $certificate->fresh()))->toBe(1);
});

test('a certificate named after its hostname needs no hostname list', function () {
    $certificate = $this->ssl->createCertificate($this->admin, ['name' => 'Shop.Example.com', 'hostnames' => '']);

    expect($certificate->hostnameList())->toBe(['shop.example.com'])
        ->and(fn () => $this->ssl->createCertificate($this->admin, ['name' => 'Main site', 'hostnames' => '']))->toThrow(DomainException::class, 'name it after its hostname');
});

test('every other name the served certificate lists gets an entry of its own, checked directly through DNS', function () {
    $web = ($this->server)('web', '10.0.0.5');
    $certificate = $this->ssl->createCertificate($this->admin, ['name' => 'shop.example.com', 'hostnames' => '', 'server_id' => (string) $web->id, 'port' => '8443']);
    $this->checker->served = ['shop.example.com', 'WWW.shop.example.com', '*.cdn.example.com', 'not a host', 'api.example.com'];

    expect($this->ssl->addEntriesFromCertificate($this->admin, $certificate))->toBe(['www.shop.example.com', 'api.example.com'])
        ->and($certificate->fresh()->hostnameList())->toBe(['shop.example.com'])
        ->and($this->checker->nameCalls)->toBe([['shop.example.com', 8443, '10.0.0.5']]);

    $www = SslCertificate::query()->where('name', 'www.shop.example.com')->first();
    $binding = $www->bindings->first();

    expect($www->hostnameList())->toBe(['www.shop.example.com'])
        ->and($www->notes)->toBe('Listed on the certificate of shop.example.com (web:8443).')
        ->and($www->bindings)->toHaveCount(1)
        ->and($binding->server_id)->toBeNull()
        ->and($binding->port)->toBe(8443)
        // Nothing twice.
        ->and($this->ssl->addEntriesFromCertificate($this->admin, $certificate->fresh()))->toBe([])
        ->and(SslCertificate::count())->toBe(3);

    // Checking the new entry asks DNS for its own name.
    $this->ssl->checkCertificate($this->admin, $www);
    expect(end($this->checker->calls))->toBe(['www.shop.example.com', 8443, null, ['www.shop.example.com']]);
});

test('an existing entry for the name, by name or first hostname, is kept', function () {
    ($this->certificate)(['name' => 'Webshop', 'hostnames' => 'www.shop.example.com']);
    ($this->certificate)(['name' => 'api.example.com', 'hostnames' => 'api2.example.com']);
    $certificate = ($this->certificate)(['name' => 'Main', 'hostnames' => 'shop.example.com', 'port' => '443']);
    $this->checker->served = ['shop.example.com', 'www.shop.example.com', 'api.example.com', 'new.example.com'];

    expect($this->ssl->addEntriesFromCertificate($this->admin, $certificate))->toBe(['new.example.com']);
});

test('without a binding or a readable certificate, nothing is added', function () {
    $unbound = ($this->certificate)();
    $bound = ($this->certificate)(['name' => 'Direct', 'port' => '443']);

    expect($this->ssl->addEntriesFromCertificate($this->admin, $unbound))->toBeNull()
        ->and($this->ssl->addEntriesFromCertificate($this->admin, $bound))->toBeNull()
        ->and(SslCertificate::count())->toBe(2)
        ->and(fn () => $this->ssl->addEntriesFromCertificate(new User(['role' => User::ROLE_USER]), $bound))->toThrow(AuthorizationException::class);
});

test('deleting a certificate clears the SSL status of the servers that served it', function () {
    $web = ($this->server)('web', '10.0.0.5');
    $certificate = ($this->certificate)(['server_id' => (string) $web->id, 'port' => '443']);
    $this->ssl->checkCertificate($this->admin, $certificate);

    expect($web->fresh()->last_ssl_status)->toBe('ok');

    $this->ssl->deleteCertificate($this->admin, $certificate);

    expect($web->fresh()->last_ssl_status)->toBeNull()
        ->and($web->fresh()->last_ssl_checked_at)->toBeNull()
        ->and(SslCheck::count())->toBe(0);
});

test('servers left with a stale SSL status are cleared', function () {
    $web = ($this->server)('web', '10.0.0.5');
    $web->last_ssl_status = 'ok';
    $web->save();
    $this->ssl->convertLegacy();

    expect($web->fresh()->last_ssl_status)->toBeNull();
});

test('bindings whose certificate or server is gone are dropped, and never break a check', function () {
    $web = ($this->server)('web', '10.0.0.5');
    $kept = ($this->certificate)(['server_id' => (string) $web->id, 'port' => '443']);
    $gone = ($this->certificate)(['name' => 'Gone', 'port' => '443']);
    $orphanCertificate = SslBinding::query()->where('certificate_id', $gone->id)->first();
    $gone->delete(); // as older versions could leave it
    $ghost = App\Models\Server::query()->create(['name' => 'ghost', 'hostname' => 'ghost.example.com', 'ssh_enabled' => false, 'ssh_username' => '']);
    $orphanServer = SslBinding::query()->create(['certificate_id' => $kept->id, 'server_id' => $ghost->id, 'port' => 8443]);
    $ghost->delete();

    // A check over every binding skips the orphan instead of failing.
    expect($this->ssl->checkAll($this->admin))->toBe(1)
        ->and(SslBinding::query()->find($orphanCertificate->id))->toBeNull()
        ->and(SslBinding::query()->find($orphanServer->id))->toBeNull()
        ->and(SslBinding::query()->where('certificate_id', $kept->id)->count())->toBe(1);
});

test('checks add entries for the names a valid certificate lists; not from an invalid one, and not when turned off', function () {
    $certificate = ($this->certificate)(['port' => '443']);
    $this->checker->extraNames = ['api.shop.example.com', 'Shop.Example.com'];
    $names = fn () => SslCertificate::query()->orderBy('id')->pluck('name')->all();

    $this->ssl->checkCertificate($this->admin, $certificate);
    // shop.example.com is this entry's own first hostname.
    expect($names())->toBe(['Shop 2026', 'www.shop.example.com', 'api.shop.example.com'])
        ->and($certificate->fresh()->hostnameList())->toBe(['shop.example.com', 'www.shop.example.com']);

    $this->checker->extraNames = ['evil.example.net'];
    $this->checker->statuses = ['dns' => HealthStatus::Critical];
    $this->ssl->checkCertificate($this->admin, $certificate->fresh());
    expect($names())->not->toContain('evil.example.net');

    (new App\Services\SettingsService())->update($this->admin, ['ssl_import_names' => '0']);
    $this->checker->statuses = [];
    $this->ssl->checkCertificate($this->admin, $certificate->fresh());
    expect($names())->not->toContain('evil.example.net');
});
