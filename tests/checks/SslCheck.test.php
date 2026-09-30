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
        $multiPort = $start('multi', 'DNS:Shop.Test, DNS:www.shop.test, DNS:*.cdn.shop.test, IP:127.0.0.1');
        $ssl = new SslCheckService("$dir/ca.pem");

        $good = $ssl->check('localhost', $goodPort);
        $wrong = $ssl->check('localhost', $wrongPort);
        $untrusted = (new SslCheckService("$dir/good.pem"))->check('localhost', $goodPort);

        expect($good->summary)->toStartWith('Valid')
            ->and($good->status)->toBe(HealthStatus::Ok)
            ->and($good->details['protocol'])->toStartWith('TLSv1')
            ->and($wrong->summary)->toContain('issued for other.test, not localhost')
            ->and($untrusted->summary)->toContain('not trusted')
            // SAN names are read from any certificate (untrusted here), connecting to the address and sending the name as SNI.
            ->and((new SslCheckService("$dir/good.pem"))->certificateNames('shop.test', $multiPort, '127.0.0.1'))->toBe(['shop.test', 'www.shop.test', '*.cdn.shop.test'])
            ->and($ssl->certificateNames('localhost', $wrongPort))->toBe(['other.test'])
            ->and($ssl->certificateNames('localhost', 1))->toBeNull();
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

test('a server that leaves out its intermediate: completed from the certificate\'s CA Issuers address, noted, and as severe as the setting says', function () {
    $dir = sys_get_temp_dir() . '/sys-aia-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $key = fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    file_put_contents("$dir/ca.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[leaf]\nsubjectAltName=DNS:localhost\nauthorityInfoAccess=caIssuers;URI:http://aia.test/int.crt\n");
    $rootKey = $key();
    $root = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Root'], $rootKey), null, $rootKey, 30, ['config' => "$dir/ca.cnf", 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
    $intKey = $key();
    $int = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Intermediate'], $intKey), $root, $rootKey, 30, ['config' => "$dir/ca.cnf", 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
    $leafKey = $key();
    $leaf = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $leafKey), $int, $intKey, 30, ['config' => "$dir/ca.cnf", 'x509_extensions' => 'leaf', 'digest_alg' => 'sha256']);
    openssl_x509_export_to_file($root, "$dir/root.pem");
    openssl_x509_export_to_file($int, "$dir/int.pem");
    openssl_x509_export_to_file($leaf, "$dir/leaf.pem");
    openssl_pkey_export_to_file($leafKey, "$dir/leaf.key");
    $otherKey = $key();
    openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'Stranger'], $otherKey), null, $otherKey, 30, ['config' => "$dir/ca.cnf", 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']), $stranger);
    openssl_x509_export($int, $intPem);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $intPem));

    $servers = [];
    $serve = function (array $extra) use ($dir, &$servers) {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $servers[] = proc_open(['openssl', 's_server', '-accept', "127.0.0.1:$port", '-cert', "$dir/leaf.pem", '-key', "$dir/leaf.key", ...$extra, '-www', '-quiet'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }

        return $port;
    };
    $checker = fn (?string $answer, HealthStatus $level = HealthStatus::Ok) => new SslCheckService("$dir/root.pem", fetch: function (string $url) use ($answer) {
        return $url === 'http://aia.test/int.crt' ? $answer : null;
    }, missingIntermediate: $level);

    try {
        $leafOnly = $serve([]);
        $fullChain = $serve(['-cert_chain', "$dir/int.pem"]);

        $noted = $checker($der)->check('localhost', $leafOnly);
        expect($noted->status)->toBe(HealthStatus::Ok)
            ->and($noted->summary)->toContain("doesn't send its intermediate certificate (Test Intermediate)")->toContain('downloaded it from http://aia.test/int.crt')
            ->and($noted->details['missing_intermediate'])->toBe(['issuer' => 'Test Intermediate', 'url' => 'http://aia.test/int.crt'])
            ->and($checker($intPem, HealthStatus::Warning)->check('localhost', $leafOnly)->status)->toBe(HealthStatus::Warning)
            ->and($checker($der, HealthStatus::Critical)->check('localhost', $leafOnly)->status)->toBe(HealthStatus::Critical)
            // Nothing to fetch, or something that doesn't complete the chain: still not trusted.
            ->and($checker(null)->check('localhost', $leafOnly)->summary)->toContain('not trusted')
            ->and($checker($stranger)->check('localhost', $leafOnly)->summary)->toContain('not trusted')
            // A server that sends the whole chain: plain OK, nothing fetched.
            ->and($checker(null)->check('localhost', $fullChain)->summary)->not->toContain('intermediate')
            ->and($checker(null)->check('localhost', $fullChain)->status)->toBe(HealthStatus::Ok);
    } finally {
        array_map('proc_terminate', $servers);
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);
    }
});

test('an intermediate is only downloaded over plain http from a public address', function (string $url) {
    $download = new ReflectionMethod(SslCheckService::class, 'download');

    expect($download->invoke(new SslCheckService(), $url))->toBeNull();
})->with(['http://127.0.0.1/int.crt', 'http://localhost/int.crt', 'http://10.1.2.3/int.crt', 'http://192.168.1.1/x', 'http://169.254.169.254/latest', 'https://crt.sectigo.com/x.crt', 'http://user@crt.sectigo.com/x', 'file:///etc/passwd']);
