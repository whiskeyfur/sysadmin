<?php

use App\Enums\HealthStatus;
use App\Services\SslCheckService;

beforeEach(function () {
    $this->ssl = new SslCheckService('/dev/null');
    $this->now = 1_800_000_000;
    $this->leaf = fn (int $fromDays, int $toDays, array $overrides = []) => array_replace_recursive([
        'subject' => ['CN' => 'example.com'],
        'issuer' => ['O' => "Let's Encrypt", 'CN' => 'R11'],
        'validFrom_time_t' => $this->now + $fromDays * 86400,
        'validTo_time_t' => $this->now + $toDays * 86400,
        'extensions' => ['subjectAltName' => 'DNS:example.com, DNS:www.example.com'],
    ], $overrides);
});

test('a valid certificate is judged by the days left', function (int $days, HealthStatus $expected) {
    $result = $this->ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, $days), 'TLSv1.3', null, $this->now);

    expect($result->status)->toBe($expected)
        ->and($result->value)->toEqual((float) $days)
        ->and($result->key)->toBe('ssl:example.com:443')
        ->and($result->details['names'])->toBe(['example.com', 'www.example.com']);
})->with([[60, HealthStatus::Ok], [8, HealthStatus::Ok], [7, HealthStatus::Warning], [1, HealthStatus::Warning]]);

test('a valid certificate is never critical, however close to expiry', function () {
    expect($this->ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, 0), null, null, $this->now)->status)->toBe(HealthStatus::Warning);
});

test('the warning period is configurable', function () {
    $ssl = new SslCheckService('/dev/null', warningDays: 30);

    expect($ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, 20), null, null, $this->now)->status)->toBe(HealthStatus::Warning)
        ->and($ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, 20), null, null, $this->now)->summary)->toContain('within 30 days')
        ->and($ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, 31), null, null, $this->now)->status)->toBe(HealthStatus::Ok);
});

test('failed verification is explained', function (array $leafArgs, ?string $error, string $reason) {
    $result = $this->ssl->evaluate('example.com', 443, false, $error, ($this->leaf)(...$leafArgs), 'TLSv1.3', null, $this->now);

    expect($result->status)->toBe(HealthStatus::Critical)
        ->and($result->summary)->toContain($reason);
})->with([
    'expired' => [[-90, -1], 'certificate verify failed', 'expired on'],
    'not yet valid' => [[2, 90], 'certificate verify failed', 'not valid until'],
    'wrong name' => [[-1, 90, ['extensions' => ['subjectAltName' => 'DNS:other.test']]], "Peer certificate CN=`other.test' did not match expected CN=`example.com'", 'issued for other.test, not example.com'],
    'untrusted' => [[-1, 90], 'certificate verify failed', 'not trusted'],
]);

test('self-signed is detected when issuer equals subject', function () {
    $leaf = ($this->leaf)(-1, 90);
    $leaf['issuer'] = $leaf['subject'];

    expect($this->ssl->evaluate('example.com', 443, false, 'certificate verify failed', $leaf, null, null, $this->now)->summary)->toContain('self-signed');
});

test('no certificate at all is critical', function () {
    $result = $this->ssl->evaluate('example.com', 8443, false, null, null, null, 'Connection refused', $this->now);

    expect($result->status)->toBe(HealthStatus::Critical)
        ->and($result->label)->toBe('example.com:8443')
        ->and($result->summary)->toContain('Connection refused');
});

test('real TLS handshakes against a local openssl server', function () {
    $dir = sys_get_temp_dir() . '/sys-ssl-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $key = fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $caKey = $key();
    // SHA-256: without it PHP signs with a digest modern OpenSSL refuses to serve ("ca md too weak").
    $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test CA'], $caKey), null, $caKey, 30, ['x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256']);
    openssl_x509_export_to_file($ca, "$dir/ca.pem");

    $servers = [];
    $start = function (string $name, string $san) use ($dir, $key, $ca, $caKey, &$servers) {
        file_put_contents("$dir/$name.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=$san\n");
        $leafKey = $key();
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $leafKey), $ca, $caKey, 30, ['config' => "$dir/$name.cnf", 'x509_extensions' => 'ext', 'digest_alg' => 'sha256']);
        openssl_x509_export_to_file($cert, "$dir/$name.pem");
        openssl_pkey_export_to_file($leafKey, "$dir/$name.key");
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $servers[] = proc_open(['openssl', 's_server', '-accept', "127.0.0.1:$port", '-cert', "$dir/$name.pem", '-key', "$dir/$name.key", '-www', '-quiet'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }

        return $port;
    };

    try {
        $goodPort = $start('good', 'DNS:localhost');
        $wrongPort = $start('wrong', 'DNS:other.test');
        $ssl = new SslCheckService("$dir/ca.pem");

        $good = $ssl->check('localhost', $goodPort);
        $wrong = $ssl->check('localhost', $wrongPort);
        $untrusted = (new SslCheckService("$dir/good.pem"))->check('localhost', $goodPort);

        expect($good->summary)->toStartWith('Valid')
            ->and($good->status)->toBe(HealthStatus::Ok)
            ->and($good->details['protocol'])->toStartWith('TLSv1')
            ->and($wrong->summary)->toContain('issued for other.test, not localhost')
            ->and($untrusted->summary)->toContain('not trusted');
    } finally {
        array_map('proc_terminate', $servers);
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);
    }
});

test('certificate names cover hostnames like a browser: one wildcard label', function (string $pattern, string $host, bool $expected) {
    expect(SslCheckService::covers($pattern, $host))->toBe($expected);
})->with([
    ['shop.example.com', 'SHOP.example.com', true],
    ['*.example.com', 'www.example.com', true],
    ['*.example.com', 'a.b.example.com', false],
    ['*.example.com', 'example.com', false],
    ['www.example.com', 'shop.example.com', false],
]);

test('a valid certificate that misses a listed hostname is a warning', function () {
    $result = $this->ssl->evaluate('example.com', 443, true, null, ($this->leaf)(-30, 90), null, null, $this->now, ['example.com', 'www.example.com', 'shop.example.com']);

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->summary)->toContain("Doesn't cover shop.example.com")
        ->and($result->details['uncovered'])->toBe(['shop.example.com']);
});
