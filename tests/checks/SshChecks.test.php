<?php

use App\Enums\HealthStatus;
use App\Services\Checks\DiskCheck;
use App\Services\Checks\LoadCheck;
use App\Services\Checks\MemoryCheck;

$gnuDf = <<<'DF'
Filesystem     1024-blocks      Used Available Capacity Mounted on
udev               8109308         0   8109308       0% /dev
tmpfs              1629924      2140   1627784       1% /run
/dev/nvme0n1p2   491134216 402000000  64115704      87% /
/dev/loop3           65408     65408         0     100% /snap/core20/2318
/dev/sdb1       1921802432 500000000 1323984568      28% /mnt/My Backups
tmpfs              8149604         0   8149604       0% /dev/shm
--inodes--
Filesystem       Inodes   IUsed    IFree IUse% Mounted on
/dev/nvme0n1p2 31227904 1200000 30027904    4% /
/dev/sdb1     122101760 121000000 1101760   99% /mnt/My Backups
DF;

test('disk: pseudo filesystems and snaps are skipped, a full disk warns', function () use ($gnuDf) {
    $result = (new DiskCheck())->evaluate($gnuDf);

    expect(array_keys($result->details['mounts']))->toBe(['/', '/mnt/My Backups'])
        ->and($result->value)->toEqual(86.2)
        ->and($result->summary)->toContain('/ 86.2% full');
});

test('disk: inode exhaustion counts too', function () use ($gnuDf) {
    $result = (new DiskCheck())->evaluate($gnuDf);

    expect($result->status)->toBe(HealthStatus::Critical)
        ->and($result->summary)->toContain('/mnt/My Backups 99.1% of inodes used');
});

test('disk: BusyBox df without inode support, all fine', function () {
    $busybox = "Filesystem           1024-blocks    Used Available Capacity Mounted on\n/dev/md0               2385528   1238032   1028712  55% /\n/dev/mapper/cachedev_0 5621548672 1621548672 4000000000  29% /volume1\n--inodes--\ndf: invalid option -- 'i'\n";
    $result = (new DiskCheck())->evaluate($busybox);

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->details['mounts'])->toHaveKeys(['/', '/volume1'])
        ->and($result->details['mounts']['/']['inode_percent'])->toBeNull();
});

test('disk: thresholds are 85% and 95%', function (int $used, HealthStatus $expected) {
    $df = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 100 $used " . (100 - $used) . " x% /data\n";

    expect((new DiskCheck())->evaluate($df)->status)->toBe($expected);
})->with([[84, HealthStatus::Ok], [85, HealthStatus::Warning], [95, HealthStatus::Critical]]);

test('disk: no usable output is unknown', function () {
    expect((new DiskCheck())->evaluate("sh: df: not found\n--inodes--\n")->status)->toBe(HealthStatus::Unknown);
});

test('load is judged per core', function (string $loadavg, string $cores, HealthStatus $expected, float $perCore) {
    $result = (new LoadCheck())->evaluate("$loadavg\n$cores\n");

    expect($result->status)->toBe($expected)
        ->and($result->value)->toEqual($perCore);
})->with([
    ['0.52 0.61 0.70 1/523 12345', '4', HealthStatus::Ok, 0.15],
    ['4.10 4.00 3.90 5/523 12345', '4', HealthStatus::Warning, 1.0],
    ['9.00 8.40 7.00 9/523 12345', '4', HealthStatus::Critical, 2.1],
    ['1.50 1.20 1.00 2/100 999', '', HealthStatus::Warning, 1.2], // core count missing: assume 1
]);

test('load without /proc/loadavg is unknown', function () {
    expect((new LoadCheck())->evaluate("4\n")->status)->toBe(HealthStatus::Unknown);
});

test('memory uses MemAvailable and reports swap', function () {
    $meminfo = "MemTotal:       16000000 kB\nMemFree:          500000 kB\nMemAvailable:    1200000 kB\nBuffers:          100000 kB\nCached:          2000000 kB\nSwapTotal:       2000000 kB\nSwapFree:        1000000 kB\n";
    $result = (new MemoryCheck())->evaluate($meminfo);

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toEqual(92.5)
        ->and($result->summary)->toContain('Swap 50%');
});

test('memory: old kernels without MemAvailable, heavy swap warns, no swap noted', function () {
    $check = new MemoryCheck();
    $old = "MemTotal: 1000 kB\nMemFree: 400 kB\nBuffers: 100 kB\nCached: 200 kB\nSwapTotal: 0 kB\nSwapFree: 0 kB\n";
    $swapping = "MemTotal: 1000 kB\nMemAvailable: 500 kB\nSwapTotal: 1000 kB\nSwapFree: 100 kB\n";

    expect($check->evaluate($old)->value)->toEqual(30.0)
        ->and($check->evaluate($old)->summary)->toContain('No swap')
        ->and($check->evaluate($swapping)->status)->toBe(HealthStatus::Warning);
});

test('memory without /proc/meminfo is unknown', function () {
    expect((new MemoryCheck())->evaluate('')->status)->toBe(HealthStatus::Unknown);
});
