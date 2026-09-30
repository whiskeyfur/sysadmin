<?php

use App\Contracts\HealthCheck;
use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\ServerConnectionException;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Models\User;
use App\Services\HealthCheckService;
use App\Services\MysqlService;
use App\Services\ServerService;
use App\Services\SshService;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->server = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_port' => 22, 'ssh_username' => 'x',
        'mysql_enabled' => '1', 'mysql_port' => 3306, 'mysql_username' => 'mon', 'mysql_password' => 'pw', 'mysql_tls' => 'off',
    ]);

    // Stand-in connection: an in-memory SQLite PDO, or a failure.
    $this->mysql = new class ($this->servers) extends MysqlService {
        public bool $fails = false;

        public function connect(Server $server, ?string $database = null): PDO
        {
            if ($this->fails) {
                throw new ServerConnectionException('Connection refused');
            }

            return new PDO('sqlite::memory:');
        }
    };

    $this->check = fn (string $key, HealthStatus $status) => new class ($key, $status) implements HealthCheck {
        public function __construct(private string $k, private HealthStatus $s)
        {
        }

        public function key(): string
        {
            return $this->k;
        }

        public function label(): string
        {
            return ucfirst($this->k);
        }

        public function run(PDO $pdo): CheckResult
        {
            if ($this->s === HealthStatus::Unknown) {
                throw new RuntimeException('boom');
            }

            return new CheckResult($this->k, ucfirst($this->k), $this->s, "{$this->k} summary", 1.5, '%', ['a' => 1]);
        }
    };

    // Stand-in SSH that records calls and returns canned output, or fails.
    $this->ssh = new class () extends SshService {
        public int $calls = 0;
        public ?string $output = null;

        public function __construct()
        {
        }

        public function run(Server $server, string $command): string
        {
            $this->calls++;

            if ($this->output === null) {
                throw new ServerConnectionException('Connection refused');
            }

            return $this->output;
        }
    };

    $this->service = fn (array $checks) => new HealthCheckService($this->mysql, $checks, $this->clock, $this->ssh);
});

test('a run stores every result and records the worst status', function () {
    $service = ($this->service)([($this->check)('alpha', HealthStatus::Ok), ($this->check)('beta', HealthStatus::Warning)]);
    $service->run($this->admin, $this->server);
    $server = $this->server->fresh();

    expect(StoredCheck::count())->toBe(2)
        ->and($server->last_health_status)->toBe('warning')
        ->and($server->last_checked_at->getTimestamp())->toBe($this->clock->time)
        ->and(array_map(fn ($c) => $c->check_key, $service->latest($server)))->toBe(['alpha', 'beta'])
        ->and($service->latest($server)[0]->details)->toBe(['a' => 1]);
});

test('a check that throws becomes unknown instead of stopping the run', function () {
    $results = ($this->service)([($this->check)('broken', HealthStatus::Unknown), ($this->check)('fine', HealthStatus::Ok)])->run($this->admin, $this->server);

    expect($results[0]->status)->toBe(HealthStatus::Unknown)
        ->and($results[0]->summary)->toContain('boom')
        ->and($results[1]->status)->toBe(HealthStatus::Ok);
});

test('a connection failure is one critical result', function () {
    $this->mysql->fails = true;
    $results = ($this->service)([($this->check)('alpha', HealthStatus::Ok)])->run($this->admin, $this->server);

    expect($results)->toHaveCount(1)
        ->and($results[0]->key)->toBe('connection')
        ->and($results[0]->status)->toBe(HealthStatus::Critical)
        ->and($this->server->fresh()->last_health_status)->toBe('critical');
});

test('results older than the retention period are pruned', function () {
    $service = ($this->service)([($this->check)('alpha', HealthStatus::Ok)]);
    $service->run($this->admin, $this->server);
    $this->clock->advance(HealthCheckService::RETENTION_DAYS * 86400 + 60);
    $service->run($this->admin, $this->server);

    expect(StoredCheck::count())->toBe(1);
});

test('history returns recent runs per check, oldest first', function () {
    $service = ($this->service)([($this->check)('alpha', HealthStatus::Ok)]);

    foreach (range(1, 3) as $run) {
        $service->run($this->admin, $this->server);
        $this->clock->advance(300);
    }

    $history = $service->history($this->server->fresh(), 2);

    expect($history['alpha'])->toHaveCount(2)
        ->and($history['alpha'][0]->checked_at->lessThan($history['alpha'][1]->checked_at))->toBeTrue();
});

test('anyone can run checks, non-admins at most once per cooldown; only for servers with SSH or MySQL', function () {
    $service = ($this->service)([]);
    $user = new User(['role' => User::ROLE_USER]);
    $service->run($user, $this->server);

    expect(fn () => $service->run($user, $this->server->fresh()))->toThrow(DomainException::class, 'Try again in')
        ->and($service->run($this->admin, $this->server->fresh()))->toBeArray();

    $this->clock->advance(App\Services\CheckCooldown::SECONDS);

    expect($service->run($user, $this->server->fresh()))->toBeArray();

    $this->servers->update($this->admin, $this->server, ['name' => 'db', 'hostname' => 'db.example.com', 'ssh_port' => 22, 'ssh_username' => 'x', 'mysql_enabled' => '']);

    expect(fn () => $service->run($this->admin, $this->server))->toThrow(DomainException::class);
});

test('deleting a server deletes its history', function () {
    ($this->service)([($this->check)('alpha', HealthStatus::Ok)])->run($this->admin, $this->server);
    $this->servers->delete($this->admin, $this->server);

    expect(StoredCheck::count())->toBe(0);
});

test('servers without trusted SSH are never contacted over SSH', function () {
    ($this->service)([])->run($this->admin, $this->server);

    expect($this->ssh->calls)->toBe(0);
});

test('SSH checks share one session and each reads its own section', function () {
    $this->server->ssh_host_key = 'ssh-ed25519 ' . base64_encode('key');
    $this->server->save();
    $service = ($this->service)([]);
    $this->ssh->output = implode("\n", [
        '@@sys-check:disk', 'Filesystem 1024-blocks Used Available Capacity Mounted on', '/dev/sda1 100 50 50 50% /', '--inodes--',
        '@@sys-check:load', '0.10 0.20 0.30 1/100 42', '4',
        '@@sys-check:memory', 'MemTotal: 1000 kB', 'MemAvailable: 800 kB', 'SwapTotal: 0 kB',
    ]);

    $results = $service->run($this->admin, $this->server);

    expect($this->ssh->calls)->toBe(1)
        ->and(array_map(fn ($r) => $r->key . ':' . $r->status->value, $results))->toBe(['disk:ok', 'load:ok', 'memory:ok']);
});

test('an SSH failure is one critical result and MySQL checks still run', function () {
    $this->server->ssh_host_key = 'ssh-ed25519 ' . base64_encode('key');
    $this->server->save();
    $results = ($this->service)([($this->check)('alpha', HealthStatus::Ok)])->run($this->admin, $this->server);

    expect(array_map(fn ($r) => $r->key . ':' . $r->status->value, $results))->toBe(['ssh:critical', 'alpha:ok']);
});

test('an SSH-only server can be checked', function () {
    $this->servers->update($this->admin, $this->server, ['name' => 'nas', 'hostname' => 'nas.example.com', 'ssh_port' => 22, 'ssh_username' => 'x', 'mysql_enabled' => '']);
    $this->server->ssh_host_key = 'ssh-ed25519 ' . base64_encode('key');
    $this->server->save();
    $this->ssh->output = "@@sys-check:disk\n@@sys-check:load\n@@sys-check:memory\n";

    $results = ($this->service)([])->run($this->admin, $this->server);

    expect(array_map(fn ($r) => $r->status, $results))->toBe([HealthStatus::Unknown, HealthStatus::Unknown, HealthStatus::Unknown]);
});

test('the shared script really runs and parses on this Linux machine', function () {
    $service = new HealthCheckService($this->mysql, [], $this->clock, $this->ssh);
    exec($service->sshScript(), $lines, $status);
    $sections = $service->splitSections(implode("\n", $lines));

    expect($status)->toBe(0)
        ->and(array_keys($sections))->toBe(['disk', 'load', 'memory'])
        ->and((new App\Services\Checks\DiskCheck())->evaluate($sections['disk'])->status)->not->toBe(HealthStatus::Unknown)
        ->and((new App\Services\Checks\LoadCheck())->evaluate($sections['load'])->status)->not->toBe(HealthStatus::Unknown)
        ->and((new App\Services\Checks\MemoryCheck())->evaluate($sections['memory'])->status)->not->toBe(HealthStatus::Unknown);
});
