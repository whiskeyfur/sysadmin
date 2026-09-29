<?php

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\User;
use App\Services\HealthCheckService;
use App\Services\ScheduledCheckService;
use App\Services\ServerService;
use App\Services\SslCheckService;
use App\Services\SslMonitorService;
use Carbon\Carbon;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);

    // Stand-ins: health checks and SSL checks record which servers they ran for.
    $this->ran = [];
    $test = $this;
    $this->health = new class ($test) extends HealthCheckService {
        public function __construct(private $test)
        {
        }

        public function canCheck(Server $server): bool
        {
            return $server->mysql_enabled;
        }

        public function runNow(Server $server): array
        {
            $this->test->ran[] = $server->name;
            $status = $this->test->failing[$server->name] ?? false ? HealthStatus::Critical : HealthStatus::Ok;
            $key = $status === HealthStatus::Critical ? 'connection' : 'connections';
            $now = Carbon::instance($this->test->clock->now())->startOfSecond();
            StoredCheck::query()->create(['server_id' => $server->id, 'check_key' => $key, 'status' => $status, 'summary' => 'x', 'checked_at' => $now]);
            $server->last_checked_at = $now;
            $server->save();

            return [new CheckResult($key, 'x', $status, 'x')];
        }
    };
    $checker = new class () extends SslCheckService {
        public array $calls = [];

        public function __construct()
        {
        }

        public function check(string $host, int $port = 443, ?string $connectTo = null, array $expectedNames = []): CheckResult
        {
            $this->calls[] = $connectTo ?? 'dns';

            return new CheckResult("ssl:$host", $host, HealthStatus::Ok, 'ok', 60.0, 'days');
        }
    };
    $this->checker = $checker;
    $this->scheduler = new ScheduledCheckService($this->health, new SslMonitorService($checker, $this->clock), $this->clock);
    $this->db = fn (string $name) => $this->servers->create($this->admin, [
        'name' => $name, 'hostname' => "$name.example.com", 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!', 'mysql_tls' => 'off',
    ]);
});

test('servers are checked when never checked, then every 5 minutes', function () {
    ($this->db)('db1');
    ($this->db)('db2');

    $this->scheduler->runDue();
    expect($this->ran)->toBe(['db1', 'db2']);

    $this->clock->advance(4 * 60);
    $this->scheduler->runDue();
    expect($this->ran)->toHaveCount(2);

    $this->clock->advance(60);
    $this->scheduler->runDue();
    expect($this->ran)->toHaveCount(4);
});

test('a server that can\'t be reached is retried less often, up to an hour, and back to normal once it answers', function () {
    ($this->db)('down');
    $this->failing = ['down' => true];
    $minutesBetweenRuns = [];
    $last = null;

    for ($minute = 0; $minute <= 300; $minute++) {
        $before = count($this->ran);
        $this->scheduler->runDue();

        if (count($this->ran) > $before) {
            $last === null || $minutesBetweenRuns[] = $minute - $last;
            $last = $minute;
        }

        $this->clock->advance(60);
    }

    expect(array_slice($minutesBetweenRuns, 0, 5))->toBe([10, 20, 40, 60, 60]);

    $this->failing = [];
    $this->clock->advance(60 * 60);
    $this->scheduler->runDue();
    expect($this->scheduler->intervalMinutes(Server::query()->where('name', 'down')->first()))->toBe(5);
});

test('servers with nothing to check are skipped; certificates on servers and served directly follow the schedule', function () {
    $sslOnly = $this->servers->create($this->admin, ['name' => 'web', 'hostname' => 'web.example.com', 'ssh_enabled' => '']);
    $this->servers->create($this->admin, ['name' => 'idle', 'hostname' => 'idle.example.com', 'ssh_enabled' => '']);
    $certificate = SslCertificate::query()->create(['name' => 'Site', 'hostnames' => 'site.example.com']);
    SslBinding::query()->create(['certificate_id' => $certificate->id, 'server_id' => $sslOnly->id, 'port' => 443]);
    SslBinding::query()->create(['certificate_id' => $certificate->id, 'server_id' => null, 'port' => 443]);

    $log = $this->scheduler->runDue();

    expect($this->ran)->toBe([])
        ->and($this->checker->calls)->toEqualCanonicalizing(['web.example.com', 'dns'])
        ->and(implode("\n", $log))->toContain('web: 1 certificate(s)')->not->toContain('idle');

    $this->clock->advance(60);
    $this->scheduler->runDue();
    expect($this->checker->calls)->toHaveCount(2);

    $this->clock->advance(5 * 60);
    $this->scheduler->runDue();
    expect($this->checker->calls)->toHaveCount(4);
});
