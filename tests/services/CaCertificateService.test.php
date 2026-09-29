<?php

use App\Services\CaCertificateService;

beforeEach(function () {
    $this->certs = new CaCertificateService();
    $this->makeCa = function (string $cn): string {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => $cn], $key), null, $key, 30);
        openssl_x509_export($cert, $pem);

        return $pem;
    };
});

test('valid PEM is accepted and normalised', function () {
    $pem = ($this->makeCa)('Test CA');
    $messy = "  some text before\r\n" . str_replace("\n", "\r\n", $pem) . "\n trailing";

    expect($this->certs->normalize($messy))->toBe(trim($pem) . "\n");
});

test('several certificates are kept, and described', function () {
    $bundle = ($this->makeCa)('Root CA') . ($this->makeCa)('Intermediate CA');
    $info = $this->certs->describe($this->certs->normalize($bundle));

    expect(array_column($info, 'subject'))->toBe(['Root CA', 'Intermediate CA'])
        ->and($info[0]['expires']->isFuture())->toBeTrue();
});

test('anything else is rejected', function (string $input) {
    $this->certs->normalize($input);
})->throws(DomainException::class)->with([
    'empty' => '',
    'not PEM' => 'hello',
    'broken PEM' => "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----",
]);

test('the system CA bundle is found', function () {
    expect(is_readable($this->certs->systemBundle()))->toBeTrue();
});

test('a CA bundle is always found, with the shipped one as a fallback', function () {
    $bundle = (new CaCertificateService())->systemBundle();

    expect(is_readable($bundle))->toBeTrue()
        ->and(file_get_contents($bundle))->toContain('-----BEGIN CERTIFICATE-----')
        ->and(is_readable(\Composer\CaBundle\CaBundle::getBundledCaBundlePath()))->toBeTrue();
});
