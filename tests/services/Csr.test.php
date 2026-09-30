<?php

use App\Exceptions\AuthorizationException;
use App\Models\CsrRequest;
use App\Models\User;
use App\Services\CsrService;

// A self-signed certificate with SANs and a subject, and its key, as a site would have.
function csrTestCertificate(array $names, array $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]): array
{
    $config = tempnam(sys_get_temp_dir(), 'cnf');
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = " . implode(',', array_map(fn ($n) => "DNS:$n", $names)) . "\n");
    $key = openssl_pkey_new($keyOptions + ['config' => $config]);
    $csr = openssl_csr_new(['commonName' => $names[0], 'organizationName' => 'Example Ltd', 'countryName' => 'US', 'localityName' => 'Sacramento'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 90, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext']);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
    unlink($config);

    return [$certPem, $keyPem];
}

// What openssl itself reads in a request.
function csrText(string $csr): string
{
    $process = proc_open(['openssl', 'req', '-noout', '-text'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $csr);
    fclose($pipes[0]);
    $text = (string) stream_get_contents($pipes[1]);
    proc_close($process);

    return $text;
}

beforeEach(function () {
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->dev = User::query()->create(['username' => 'dev', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    $this->csr = new CsrService(cipher: $this->cipher, clock: $this->clock);
});

test('a certificate is read for the form: names, subject, key kind, issuer, dates', function () {
    [$cert] = csrTestCertificate(['www.example.com', 'example.com', 'shop.example.com']);
    $info = $this->csr->describe("some text before\n$cert\nand after");

    expect($info['common_name'])->toBe('www.example.com')
        ->and($info['names'])->toBe(['www.example.com', 'example.com', 'shop.example.com'])
        ->and($info['subject'])->toBe(['O' => 'Example Ltd', 'L' => 'Sacramento', 'C' => 'US'])
        ->and($info['key'])->toBe('rsa-2048')
        ->and($info['valid_to'])->not->toBeNull()
        ->and($info['fingerprint'])->toMatch('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/')
        ->and(fn () => $this->csr->describe('not a certificate'))->toThrow(DomainException::class, 'isn\'t a certificate');
});

test('a request with a new key carries the names and subject; the key is kept encrypted and can be fetched', function (string $key, string $expect) {
    $request = $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'names' => "example.com\nshop.example.com, www.example.com", 'subject' => ['O' => 'Example Ltd', 'C' => 'us'], 'key' => $key]);
    $text = csrText($request->csr);
    $private = $this->csr->privateKey($this->admin, $request);

    expect($request->key_source)->toBe('new')
        ->and($request->names)->toBe(['www.example.com', 'example.com', 'shop.example.com'])
        ->and($request->subject)->toBe(['O' => 'Example Ltd', 'C' => 'US'])
        ->and($text)->toContain('CN = www.example.com')->toContain('O = Example Ltd')->toContain('C = US')
        ->toContain('DNS:www.example.com, DNS:example.com, DNS:shop.example.com')->toContain($expect)
        ->and($request->fresh()->private_key)->not->toContain('PRIVATE KEY')
        ->and($private)->toContain('PRIVATE KEY')
        // The kept key is the one the request was signed with.
        ->and(openssl_pkey_get_details(openssl_pkey_get_private($private))['key'])->toBe(openssl_pkey_get_details(openssl_csr_get_public_key($request->csr))['key']);
})->with([
    'RSA 2048' => ['rsa-2048', 'Public-Key: (2048 bit)'],
    'EC P-256' => ['ec-256', 'prime256v1'],
    'EC P-384' => ['ec-384', 'secp384r1'],
]);

test('an existing key is used as it is, if it belongs to the certificate, and not kept', function () {
    [$cert, $key] = csrTestCertificate(['www.example.com', 'example.com']);
    [, $otherKey] = csrTestCertificate(['other.example.com']);
    $request = $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'names' => 'example.com', 'private_key' => $key, 'certificate_pem' => $cert]);

    expect($request->key_source)->toBe('provided')
        ->and($request->private_key)->toBeNull()
        ->and(openssl_pkey_get_details(openssl_csr_get_public_key($request->csr))['key'])->toBe(openssl_pkey_get_details(openssl_pkey_get_public($cert))['key'])
        ->and(fn () => $this->csr->privateKey($this->admin, $request))->toThrow(DomainException::class, 'isn\'t kept')
        ->and(fn () => $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'private_key' => $otherKey, 'certificate_pem' => $cert]))->toThrow(DomainException::class, 'doesn\'t belong')
        ->and(fn () => $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'private_key' => 'garbage']))->toThrow(DomainException::class, 'can\'t be read');
});

test('input is checked, and only admins make requests or get keys', function () {
    expect(fn () => $this->csr->create($this->admin, ['common_name' => '', 'key' => 'rsa-2048']))->toThrow(DomainException::class, 'common name')
        ->and(fn () => $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'names' => 'bad name!', 'key' => 'rsa-2048']))->toThrow(DomainException::class, 'isn\'t a host name')
        ->and(fn () => $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'subject' => ['C' => 'USA'], 'key' => 'rsa-2048']))->toThrow(DomainException::class, 'two-letter')
        ->and(fn () => $this->csr->create($this->admin, ['common_name' => 'www.example.com', 'key' => 'dsa-1024']))->toThrow(DomainException::class, 'kind of key')
        ->and(fn () => $this->csr->create($this->dev, ['common_name' => 'www.example.com', 'key' => 'rsa-2048']))->toThrow(AuthorizationException::class)
        ->and(CsrRequest::query()->count())->toBe(0);

    $request = $this->csr->create($this->admin, ['common_name' => '*.example.com', 'names' => '10.0.0.5', 'key' => 'ec-256']);
    expect(csrText($request->csr))->toContain('DNS:*.example.com, IP Address:10.0.0.5')
        ->and(fn () => $this->csr->privateKey($this->dev, $request))->toThrow(AuthorizationException::class);
});
