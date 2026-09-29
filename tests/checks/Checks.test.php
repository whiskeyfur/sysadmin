<?php

use App\Enums\HealthStatus;
use App\Services\Checks\BufferPoolCheck;
use App\Services\Checks\ConnectionsCheck;
use App\Services\Checks\CrashedTablesCheck;
use App\Services\Checks\ReplicationCheck;
use App\Services\Checks\ServerStatusCheck;

test('the worst status wins, and unknown is never hidden behind ok or warning', function () {
    expect(HealthStatus::worst([HealthStatus::Ok, HealthStatus::Warning]))->toBe(HealthStatus::Warning)
        ->and(HealthStatus::worst([HealthStatus::Warning, HealthStatus::Unknown]))->toBe(HealthStatus::Unknown)
        ->and(HealthStatus::worst([HealthStatus::Unknown, HealthStatus::Critical]))->toBe(HealthStatus::Critical)
        ->and(HealthStatus::worst([]))->toBe(HealthStatus::Ok);
});

test('server status warns after a recent restart', function (int $uptime, HealthStatus $expected, string $shown) {
    $result = (new ServerStatusCheck())->evaluate('10.11.14-MariaDB', $uptime);

    expect($result->status)->toBe($expected)
        ->and($result->summary)->toContain($shown)
        ->and($result->value)->toEqual($uptime);
})->with([
    [120, HealthStatus::Warning, 'up 2 min'],
    [3599, HealthStatus::Warning, 'up 59 min'],
    [3600, HealthStatus::Ok, 'up 1 h 0 min'],
    [90061, HealthStatus::Ok, 'up 1 d 1 h'],
]);

test('connections: warning at 80%, critical at 95%', function (int $connected, HealthStatus $expected) {
    expect((new ConnectionsCheck())->evaluate($connected, 90, 100)->status)->toBe($expected);
})->with([[79, HealthStatus::Ok], [80, HealthStatus::Warning], [94, HealthStatus::Warning], [95, HealthStatus::Critical]]);

test('connections without max_connections is unknown', function () {
    expect((new ConnectionsCheck())->evaluate(5, 5, 0)->status)->toBe(HealthStatus::Unknown);
});

test('buffer pool: warns below 95%, ignores too few reads', function (int $requests, int $diskReads, HealthStatus $expected) {
    expect((new BufferPoolCheck())->evaluate($requests, $diskReads)->status)->toBe($expected);
})->with([
    [100000, 10000, HealthStatus::Warning],
    [100000, 5000, HealthStatus::Ok],
    [500, 400, HealthStatus::Ok],
]);

test('replication: not a replica is fine', function () {
    expect((new ReplicationCheck())->evaluate([])->summary)->toBe('Not a replica.');
});

test('replication: stopped threads are critical and show the error', function () {
    $result = (new ReplicationCheck())->evaluate([
        ['Connection_name' => '', 'Slave_IO_Running' => 'Yes', 'Slave_SQL_Running' => 'No', 'Seconds_Behind_Master' => null, 'Last_IO_Error' => '', 'Last_SQL_Error' => "Duplicate entry '1' for key 'PRIMARY'"],
    ]);

    expect($result->status)->toBe(HealthStatus::Critical)
        ->and($result->summary)->toContain('stopped')->toContain('Duplicate entry');
});

test('replication: lag thresholds, old and new column names, multi-source', function (int $lag, HealthStatus $expected) {
    $result = (new ReplicationCheck())->evaluate([
        ['Connection_name' => 'east', 'Replica_IO_Running' => 'Yes', 'Replica_SQL_Running' => 'Yes', 'Seconds_Behind_Source' => 0],
        ['Connection_name' => 'west', 'Slave_IO_Running' => 'Yes', 'Slave_SQL_Running' => 'Yes', 'Seconds_Behind_Master' => $lag],
    ]);

    expect($result->status)->toBe($expected)
        ->and($result->value)->toEqual($lag)
        ->and($result->summary)->toContain('east')->toContain('west');
})->with([[59, HealthStatus::Ok], [60, HealthStatus::Warning], [600, HealthStatus::Critical]]);

test('crashed tables: a table MariaDB cannot open is critical', function () {
    $result = (new CrashedTablesCheck())->evaluate([
        ['db' => 'shop', 'name' => 'orders', 'engine' => null, 'comment' => "Table './shop/orders' is marked as crashed and should be repaired"],
        ['db' => 'shop', 'name' => 'items', 'engine' => 'InnoDB', 'comment' => ''],
    ], [], 0);

    expect($result->status)->toBe(HealthStatus::Critical)
        ->and($result->summary)->toContain('shop.orders')->toContain('marked as crashed')
        ->and($result->details['problems'])->toHaveKey('shop.orders');
});

test('crashed tables: CHECK TABLE errors and non-OK statuses are critical', function (array $rows) {
    expect((new CrashedTablesCheck())->evaluate([], $rows, 1)->status)->toBe(HealthStatus::Critical);
})->with([
    'error row' => [[['Table' => 'shop.log', 'Op' => 'check', 'Msg_type' => 'error', 'Msg_text' => 'Table is marked as crashed'], ['Table' => 'shop.log', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Operation failed']]],
    'corrupt status' => [[['Table' => 'shop.log', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Corrupt']]],
]);

test('crashed tables: OK and up-to-date results are fine', function () {
    $result = (new CrashedTablesCheck())->evaluate(
        [['db' => 'shop', 'name' => 'log', 'engine' => 'Aria', 'comment' => '']],
        [['Table' => 'shop.log', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Table is already up to date'], ['Table' => 'shop.cache', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'OK']],
        2,
    );

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->summary)->toContain('1 tables, 2 MyISAM/Aria checked');
});

test('crashed tables: warnings and missing privileges are reported, not hidden', function () {
    $check = new CrashedTablesCheck();

    expect($check->evaluate([], [['Table' => 'a.b', 'Msg_type' => 'warning', 'Msg_text' => '1 client is using or hasn\'t closed the table properly']], 1)->status)->toBe(HealthStatus::Warning)
        ->and($check->evaluate([], [['Table' => 'a.b', 'Msg_type' => 'error', 'Msg_text' => 'SELECT command denied to user']], 1)->status)->toBe(HealthStatus::Unknown);
});
