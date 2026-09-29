<?php

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\User;
use App\Services\HealthCheckService;
use App\Services\MariadbLogService;
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
            // A failing server fails its MariaDB connection, or its SSH connection with sshDown.
            $key = $status === HealthStatus::Critical ? (($this->test->sshDown ?? false) ? 'ssh' : 'connection') : 'connections';
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
    // Stand-in log import: records the servers it imported from.
    $this->imported = [];
    $this->logs = new class ($test) extends MariadbLogService {
        public bool $fails = false;

        public function __construct(private $test)
        {
        }

        public array $usedDatabase = [];

        public function importNow(Server $server, bool $useDatabase = true): array
        {
            $this->test->imported[] = $server->name;
            $this->usedDatabase[] = $useDatabase;

            if ($this->fails) {
                throw new App\Exceptions\ServerConnectionException('ssh refused');
            }

            $server->log_imported_at = Carbon::instance($this->test->clock->now());
            $server->log_import_message = '3 new entries.';
            $server->save();

            return ['configuration' => [], 'sources' => []];
        }
    };
    $this->scheduler = new ScheduledCheckService($this->health, new SslMonitorService($checker, $this->clock), $this->clock, $this->logs);
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

test('MariaDB logs of servers with SSH set up are imported on their own interval', function () {
    $both = ($this->db)('both');
    $both->ssh_enabled = true;
    $both->ssh_username = 'ops';
    $both->ssh_host_key = 'ssh-ed25519 AAAA';
    $both->save();
    ($this->db)('db-only'); // no SSH: nothing to import from

    $log = $this->scheduler->runDue();
    expect($this->imported)->toBe(['both'])
        ->and(implode("\n", $log))->toContain('both: log import: 3 new entries.');

    $this->clock->advance(59 * 60);
    $this->scheduler->runDue();
    expect($this->imported)->toHaveCount(1);

    $this->clock->advance(60);
    $this->scheduler->runDue();
    expect($this->imported)->toHaveCount(2);
});

test('a failed scheduled import is recorded and not retried before the interval; 0 turns imports off', function () {
    $server = ($this->db)('both');
    $server->ssh_enabled = true;
    $server->ssh_username = 'ops';
    $server->ssh_host_key = 'ssh-ed25519 AAAA';
    $server->save();
    $this->logs->fails = true;

    $this->scheduler->runDue();
    $this->clock->advance(5 * 60);
    $this->scheduler->runDue();

    expect($this->imported)->toBe(['both'])
        ->and($server->fresh()->log_import_message)->toContain('ssh refused');

    (new App\Services\SettingsService())->update(new User(['role' => User::ROLE_ADMIN]), ['mysql_log_import_minutes' => '0']);
    $this->clock->advance(2 * 3600);
    $this->scheduler->runDue();

    expect($this->imported)->toHaveCount(1);
});

test('with MariaDB down its log is still imported, without logging into it; with SSH failing, not at all', function () {
    $server = ($this->db)('down');
    $server->ssh_enabled = true;
    $server->ssh_username = 'ops';
    $server->ssh_host_key = 'ssh-ed25519 AAAA';
    $server->save();
    $this->failing = ['down' => true]; // the stand-in fails the MariaDB connection

    $this->scheduler->runDue();

    expect($this->imported)->toBe(['down'])
        ->and($this->logs->usedDatabase)->toBe([false]);

    $this->sshDown = true;
    $this->clock->advance(3 * 3600);
    $this->scheduler->runDue();

    expect($this->imported)->toHaveCount(1);
});
