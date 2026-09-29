<?php

namespace App\Services;

use Carbon\Carbon;
use DomainException;

/**
 * CA certificates pasted by admins for MySQL TLS verification. They are
 * public, so they are stored as plain PEM.
 */
class CaCertificateService
{
    private const MAX_BYTES = 65536;

    /**
     * Validate pasted PEM and return it normalised (certificates only).
     *
     * @throws DomainException with a user-facing message.
     */
    public function normalize(string $pem): string
    {
        if (strlen($pem) > self::MAX_BYTES) {
            throw new DomainException('The CA certificate is too large.');
        }

        $blocks = $this->blocks($pem);

        if ($blocks === []) {
            throw new DomainException('Paste the CA certificate in PEM format (-----BEGIN CERTIFICATE----- ...).');
        }

        foreach ($blocks as $block) {
            if (openssl_x509_parse($block) === false) {
                throw new DomainException('The CA certificate could not be read. Paste the whole PEM block.');
            }
        }

        return implode("\n", $blocks) . "\n";
    }

    /**
     * @return list<array{subject: string, expires: Carbon}>
     */
    public function describe(string $pem): array
    {
        $certificates = [];

        foreach ($this->blocks($pem) as $block) {
            $info = openssl_x509_parse($block);

            if ($info === false) {
                continue;
            }

            $certificates[] = [
                'subject' => (string) ($info['subject']['CN'] ?? $info['name'] ?? 'unknown'),
                'expires' => Carbon::createFromTimestamp((int) $info['validTo_time_t']),
            ];
        }

        return $certificates;
    }

    /**
     * The system's trusted CA bundle, for verifying publicly issued certificates.
     */
    public function systemBundle(): string
    {
        $candidates = [
            openssl_get_cert_locations()['default_cert_file'] ?? '',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('No system CA bundle found.');
    }

    /**
     * @return list<string>
     */
    private function blocks(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----/', $pem, $matches);

        // Rebuild each block from its base64 body so stray whitespace and CRLFs don't matter.
        return array_values(array_map(
            fn (string $body) => "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) preg_replace('/\s+/', '', $body), 64, "\n") . '-----END CERTIFICATE-----',
            $matches[1],
        ));
    }
}
