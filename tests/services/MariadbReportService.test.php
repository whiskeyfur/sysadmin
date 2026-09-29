<?php

use App\Enums\HealthStatus;
use App\Models\HealthCheck as StoredCheck;
use App\Models\User;
use App\Services\MariadbReportService;
use App\Services\ServerService;
use App\Utils\LineChart;
use Carbon\Carbon;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->server = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!', 'mysql_tls' => 'off',
    ]);
    $this->reports = new MariadbReportService($this->clock);
    $this->store = function (int $minutesAgo, string $key, ?float $value, array $details = []) {
        StoredCheck::query()->create([
            'server_id' => $this->server->id, 'check_key' => $key, 'status' => HealthStatus::Ok, 'summary' => 'x',
            'value' => $value, 'details' => $details, 'checked_at' => Carbon::instance($this->clock->now())->subMinutes($minutesAgo),
        ]);
    };
    // One MariaDB run: connections, buffer pool, replication, crashed tables, uptime.
    $this->run = function (int $minutesAgo, int $connected, ?float $hit, ?int $lag, int $crashed, int $uptime) {
        ($this->store)($minutesAgo, 'connections', round($connected / 151 * 100, 1), ['connected' => $connected, 'peak' => 9, 'max' => 151]);
        ($this->store)($minutesAgo, 'innodb_buffer_pool', $hit);
        ($this->store)($minutesAgo, 'replication', $lag === null ? null : (float) $lag, $lag === null ? [] : ['replicas' => 1]);
        ($this->store)($minutesAgo, 'crashed_tables', (float) $crashed);
        ($this->store)($minutesAgo, 'server_status', (float) $uptime);
    };
});

test('a report turns stored runs into series and rows; values a run didn\'t measure leave gaps', function () {
    ($this->run)(60, 3, 99.5, 4, 0, 86400 * 2);
    ($this->run)(5, 6, null, 12, 1, 600);

    $report = $this->reports->report($this->server, '24h');

    expect(array_column(reset($report['connections']), 1))->toBe([2.0, 4.0])
        ->and(array_column(reset($report['buffer_pool']), 1))->toBe([99.5])
        ->and(array_column(reset($report['lag']), 1))->toBe([4.0, 12.0])
        ->and(array_column(reset($report['uptime']), 1))->toBe([2.0, 0.01])
        ->and($report['rows'][1])->toMatchArray(['connected' => 6, 'max_connections' => 151, 'peak' => 9, 'buffer_pool' => null, 'lag' => 12.0, 'crashed' => 1.0]);
});

test('servers that aren\'t replicas have no lag series', function () {
    ($this->run)(5, 3, 99.5, null, 0, 1000);

    expect($this->reports->report($this->server, '24h')['lag'])->toBe([]);
});

test('when averaging, crashed tables keep the worst of each interval', function () {
    for ($i = 0; $i < 400; $i++) {
        ($this->run)($i * 30, 3, 99.0, null, $i === 7 ? 2 : 0, 100000);
    }

    $report = $this->reports->report($this->server, '30d');

    expect($report['bucket_minutes'])->not->toBeNull()
        ->and(max(array_column(reset($report['crashed']), 1)))->toBe(2.0)
        ->and(max(array_column($report['rows'], 'crashed')))->toBe(2.0);
});

test('a chart scale can start above zero, for values in a narrow band', function () {
    $svg = LineChart::render('Hit ratio', ['x' => [[0, 99.2], [10, 99.8]]], 0, 10, '%', 100, 95);

    expect($svg)->toContain('>95%<')->toContain('>100%<')->not->toContain('>0%<');
});

test('disk space, database sizes and I/O throughput read through MariaDB', function () {
    $mb = 1048576;
    foreach ([[120, 10, 100, 40.0], [60, 20, 160, 41.0], [0, 5, 20, 42.0]] as [$ago, $size, $read, $root]) {
        ($this->store)($ago, 'disk_space', $root, ['mounts' => ['/' => ['used_percent' => $root], '/data' => ['used_percent' => 70.0]]]);
        ($this->store)($ago, 'database_size', (float) $size, ['databases' => ['shop' => $size * $mb]]);
        ($this->store)($ago, 'file_io', null, ['bytes_read' => $read * $mb, 'bytes_written' => 0]);
    }

    $report = $this->reports->report($this->server, '24h');

    expect(array_keys($report['disk']))->toBe(['/', '/data'])
        ->and(array_column($report['disk']['/'], 1))->toBe([40.0, 41.0, 42.0])
        ->and(array_column($report['sizes']['shop'], 1))->toBe([10.0, 20.0, 5.0])
        // 60 MB in the hour after the first run; then the counters dropped (a restart): no rate across it.
        ->and(array_column($report['io']['Read'], 1))->toBe([60.0])
        ->and($report['rows'][1])->toMatchArray(['disk' => 70.0, 'db_size_mb' => 20.0, 'io_read' => 60.0]);
});
