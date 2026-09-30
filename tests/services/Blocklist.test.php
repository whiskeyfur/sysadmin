<?php

use App\Models\ApacheAccessEntry;
use App\Models\BlocklistIp;
use App\Services\BlocklistService;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/sys-blocklists-' . bin2hex(random_bytes(4));
    $this->downloads = [
        'https://lists.blocklist.de/lists/all.txt' => "1.2.3.4\n2001:db8::7\n\nnot an address\n",
        'https://www.spamhaus.org/drop/drop_v4.json' => "{\"cidr\":\"10.20.0.0/16\",\"sblid\":\"SBL1\",\"rir\":\"x\"}\n{\"cidr\":\"1.2.3.0/29\",\"sblid\":\"SBL2\"}\n{\"type\":\"metadata\",\"records\":2}\n",
        'https://www.spamhaus.org/drop/drop_v6.json' => "{\"cidr\":\"2001:db8:ff00::/40\",\"sblid\":\"SBL3\"}\n",
    ];
    $this->service = fn () => new BlocklistService($this->dir, function (string $url) {
        $body = $this->downloads[$url] ?? null;

        return $body ?? throw new RuntimeException("404 for $url");
    }, $this->clock);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('lists are parsed from plain lines, Spamhaus JSON lines and DROP text, and matched by address and range', function () {
    $service = ($this->service)();

    expect($service->parse("; comment\n1.2.3.0/24 ; SBL1\n{\"cidr\":\"5.6.0.0/16\"}\n{\"type\":\"metadata\"}\n2001:DB8::1\nnonsense\n9.9.9.9/33\n"))->toBe(['1.2.3.0/24', '5.6.0.0/16', '2001:db8::1']);

    $service->refresh();

    expect($service->match('1.2.3.4'))->toBe(['blocklist.de', 'Spamhaus DROP'])
        ->and($service->match('1.2.3.3'))->toBe(['Spamhaus DROP'])
        ->and($service->match('1.2.3.8'))->toBe([])
        ->and($service->match('10.20.255.255'))->toBe(['Spamhaus DROP'])
        ->and($service->match('10.21.0.0'))->toBe([])
        // IPv6: the same address written another way; and a range.
        ->and($service->match('2001:0db8:0000::0007'))->toBe(['blocklist.de'])
        ->and($service->match('2001:db8:ff12::1'))->toBe(['Spamhaus DROP'])
        ->and($service->match('2001:db8:1::1'))->toBe([])
        ->and($service->match('garbage'))->toBe([]);
});

test('a failed download keeps the previous copy; new clients are checked as they come, all again after a download', function () {
    ApacheAccessEntry::query()->insert([
        ['server_id' => 1, 'source' => 'x', 'requested_at' => '2026-09-01 00:00:00', 'client' => '1.2.3.4', 'status' => 200, 'bytes' => 0],
        ['server_id' => 1, 'source' => 'x', 'requested_at' => '2026-09-01 00:00:00', 'client' => '8.8.8.8', 'status' => 200, 'bytes' => 0],
    ]);

    $report = ($this->service)()->refresh();
    expect($report)->toContain('blocklist.de: 2 entries.')
        ->and(BlocklistIp::query()->pluck('sources', 'ip')->all())->toBe(['1.2.3.4' => 'blocklist.de, Spamhaus DROP', '8.8.8.8' => null]);

    // blocklist.de fails now, and names 8.8.8.8 in a copy we never get: the old copy is used.
    unset($this->downloads['https://lists.blocklist.de/lists/all.txt']);
    $report = ($this->service)()->refresh();
    expect(implode("\n", $report))->toContain('blocklist.de: download failed (404 for https://lists.blocklist.de/lists/all.txt); the previous copy stays.')
        ->and(($this->service)()->match('1.2.3.4'))->toContain('blocklist.de')
        ->and(($this->service)()->status()['blocklist.de']['error'])->toContain('404');

    // A new client: checked by checkNew(), the known ones left alone.
    ApacheAccessEntry::query()->insert(['server_id' => 1, 'source' => 'x', 'requested_at' => '2026-09-01 00:00:00', 'client' => '10.20.1.1', 'status' => 200, 'bytes' => 0]);
    expect(($this->service)()->checkNew())->toBe(1)
        ->and(BlocklistIp::query()->count())->toBe(3)
        ->and(($this->service)()->listed(['10.20.1.1', '8.8.8.8', '1.2.3.4']))->toBe(['1.2.3.4' => 'blocklist.de, Spamhaus DROP', '10.20.1.1' => 'Spamhaus DROP']);

    // Addresses no longer in the access logs are forgotten on the next full check.
    ApacheAccessEntry::query()->where('client', '8.8.8.8')->delete();
    ($this->service)()->recheckAll();
    expect(BlocklistIp::query()->pluck('ip')->sort()->values()->all())->toBe(['1.2.3.4', '10.20.1.1']);
});
