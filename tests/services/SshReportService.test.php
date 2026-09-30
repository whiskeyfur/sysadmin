<?php

use App\Enums\HealthStatus;
use App\Models\HealthCheck as StoredCheck;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SshReportService;
use App\Utils\LineChart;
use Carbon\Carbon;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->server = $this->servers->create($this->admin, ['name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'deploy']);
    $this->reports = new SshReportService($this->clock);

    // One stored SSH run, $hoursAgo before the test clock.
    $this->run = function (int $hoursAgo, array $mounts, array $load, float $memory, ?float $swap) {
        $at = Carbon::instance($this->clock->now())->subHours($hoursAgo);
        $store = fn (string $key, ?float $value, array $details) => StoredCheck::query()->create([
            'server_id' => $this->server->id, 'check_key' => $key, 'status' => HealthStatus::Ok, 'summary' => 'x',
            'value' => $value, 'details' => $details, 'checked_at' => $at,
        ]);
        $store('disk', max(array_column($mounts, 'used_percent')), ['mounts' => $mounts]);
        $store('load', round($load[1] / $load[3], 2), ['load1' => $load[0], 'load5' => $load[1], 'load15' => $load[2], 'cores' => $load[3]]);
        $store('memory', $memory, ['swap_percent' => $swap]);
    };
});

test('a report turns stored runs into series per filesystem, load and memory, and table rows', function () {
    ($this->run)(30, ['/' => ['used_percent' => 40.0], '/data' => ['used_percent' => 70.0]], [0.5, 0.4, 0.3, 2], 50.0, 1.0);
    ($this->run)(5, ['/' => ['used_percent' => 45.0], '/data' => ['used_percent' => 72.5]], [1.5, 1.2, 0.9, 2], 60.0, null);
    ($this->run)(24 * 40, ['/' => ['used_percent' => 10.0]], [9, 9, 9, 1], 99.0, 99.0); // outside every range

    $report = $this->reports->report($this->server, '7d');

    expect(array_keys($report['disk']))->toBe(['/', '/data'])
        ->and(array_column($report['disk']['/data'], 1))->toBe([70.0, 72.5])
        ->and(array_column($report['load']['5 min'], 1))->toBe([0.4, 1.2])
        ->and(array_column($report['memory']['Memory'], 1))->toBe([50.0, 60.0])
        ->and(array_column($report['memory']['Swap'], 1))->toBe([1.0])
        ->and(count($report['rows']))->toBe(2)
        ->and($report['rows'][1])->toMatchArray(['disk' => 72.5, 'disk_mount' => '/data', 'load1' => 1.5, 'cores' => 2, 'per_core' => 0.6, 'memory' => 60.0, 'swap' => null]);

    expect(count($this->reports->report($this->server, '24h')['rows']))->toBe(1)
        ->and(count($this->reports->report($this->server, 'bogus')['rows']))->toBe(2);
});

test('charts are inline SVG with a line and hoverable points per series, and escape names', function () {
    $svg = LineChart::render('Disk <used>', ['/data & more' => [[100, 20.0], [200, 40.0]], 'solo' => [[150, 10.0]]], 100, 200, '%', 100);

    expect($svg)->toContain('<svg class="chart"')
        ->and(substr_count($svg, '<polyline'))->toBe(1)
        ->and(substr_count($svg, '<circle'))->toBe(3)
        ->and($svg)->toContain('Disk &lt;used&gt;')
        ->and($svg)->toContain('/data &amp; more: 40%')
        ->and($svg)->not->toContain('<used>')
        ->and(LineChart::render('Empty', [], 0, 1))->toContain('No data');
});

test('more runs than a chart can show are averaged into even intervals', function () {
    // 30 days of runs every 5 minutes.
    for ($i = 0; $i < 8000; $i += 1) {
        $at = Carbon::instance($this->clock->now())->subMinutes($i * 5);
        StoredCheck::query()->create(['server_id' => $this->server->id, 'check_key' => 'memory', 'status' => HealthStatus::Ok, 'summary' => 'x', 'value' => $i % 2 ? 40.0 : 60.0, 'details' => [], 'checked_at' => $at]);
    }

    $report = $this->reports->report($this->server, '30d');

    expect($report['bucket_minutes'])->toBe(240)
        ->and(count($report['memory']['Memory']))->toBeLessThanOrEqual(SshReportService::MAX_POINTS)
        ->and(count($report['rows']))->toBeLessThanOrEqual(SshReportService::MAX_POINTS)
        ->and($report['memory']['Memory'][10][1])->toBe(50.0)
        ->and($this->reports->report($this->server, '24h')['bucket_minutes'])->toBeNull();
});

test('a chosen start and end shows only that stretch, runs after the end left out', function () {
    ($this->run)(30, ['/' => ['used_percent' => 40.0]], [0.5, 0.4, 0.3, 2], 50.0, null);
    ($this->run)(20, ['/' => ['used_percent' => 41.0]], [0.5, 0.4, 0.3, 2], 51.0, null);
    ($this->run)(5, ['/' => ['used_percent' => 45.0]], [1.5, 1.2, 0.9, 2], 60.0, null);
    $now = Carbon::instance($this->clock->now())->getTimestamp();

    $report = $this->reports->report($this->server, ($now - 25 * 3600) . '-' . ($now - 10 * 3600));

    expect(array_column($report['memory']['Memory'], 1))->toBe([51.0])
        ->and($report['from']->getTimestamp())->toBe($now - 25 * 3600)
        ->and($report['to']->getTimestamp())->toBe($now - 10 * 3600)
        ->and(LineChart::render('Memory', $report['memory'], $now - 25 * 3600, $now - 10 * 3600, '%'))->toContain('data-from="' . ($now - 25 * 3600) . '"');
});

test('the period comes from start and end fields (app time zone), a zoomed range, or a preset', function () {
    $zone = App\Utils\LocalTime::zone();
    $start = (new DateTimeImmutable('2026-09-01 08:00', $zone))->getTimestamp();

    expect(App\Services\HistoryReport::period('7d', '2026-09-01T08:00', '2026-09-02T08:00'))->toBe($start . '-' . ($start + 86400))
        // Swapped, too short, too long.
        ->and(App\Services\HistoryReport::period('', '2026-09-02T08:00', '2026-09-01T08:00'))->toBe($start . '-' . ($start + 86400))
        ->and(App\Services\HistoryReport::period('', '2026-09-01T08:00', '2026-09-01T08:01'))->toBe($start . '-' . ($start + 600))
        ->and(App\Services\HistoryReport::period('', '2026-09-01T08:00', '2026-12-01T08:00'))->toBe($start . '-' . ($start + 31 * 86400))
        // Garbage dates fall back to the range.
        ->and(App\Services\HistoryReport::period('24h', 'soon', 'later'))->toBe('24h')
        ->and(App\Services\HistoryReport::period('1780000000-1780003600'))->toBe('1780000000-1780003600')
        ->and(App\Services\HistoryReport::period('1780000000-1780000060'))->toBe('7d')
        ->and(App\Services\HistoryReport::period('30d'))->toBe('30d')
        ->and(App\Services\HistoryReport::period('bogus'))->toBe('7d')
        ->and(App\Services\HistoryReport::custom('7d'))->toBeNull();
});
