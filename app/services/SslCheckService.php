<?php

namespace App\Services;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Utils\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Checks a site's HTTPS certificate, downloaded straight from the site.
 *
 * Two TLS connections: one with full verification (trusted chain and
 * hostname, via SNI), which gives the verdict; and one without, which reads
 * the certificate so a failure can be explained precisely (PHP reports
 * expired, self-signed and untrusted certificates all as "certificate
 * verify failed").
 *
 * An invalid certificate is critical. A valid one is a warning once it
 * expires within the warning period (an admin setting, default 7 days).
 */
class SslCheckService
{
    public const TIMEOUT = 10;

    /**
     * @param string|null $caFile trust anchors; null means the system CA bundle
     * @param int $warningDays a valid certificate expiring within this many days is a warning
     */
    public function __construct(
        private readonly ?string $caFile = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly CaCertificateService $certificates = new CaCertificateService(),
        private readonly int $warningDays = SettingsService::DEFAULTS[SettingsService::SSL_WARNING_DAYS],
    ) {
    }

    public function warningDays(): int
    {
        return $this->warningDays;
    }

    /**
     * @param string $host the hostname the certificate is for: sent as SNI and verified
     * @param string|null $connectTo the address to connect to (a server's hostname or IP); null = $host via DNS
     * @param list<string> $expectedNames every hostname the certificate should cover; a gap is a warning
     */
    public function check(string $host, int $port = 443, ?string $connectTo = null, array $expectedNames = []): CheckResult
    {
        $connectTo ??= $host;
        [$verified, $verifyError] = $this->connect($connectTo, $host, $port, true);
        // Only needed when verification failed: read the certificate to explain why.
        [$unverified, $connectError] = $verified === null ? $this->connect($connectTo, $host, $port, false) : [null, null];

        $info = $verified ?? $unverified;

        return $this->evaluate(
            $host,
            $port,
            $verified !== null,
            $verifyError,
            $info === null ? null : $info['leaf'],
            $info === null ? null : $info['protocol'],
            $connectError,
            $this->clock->now()->getTimestamp(),
            $expectedNames,
        );
    }

    /**
     * Whether a certificate name (possibly "*.example.com") covers a hostname.
     * A wildcard covers exactly one label, as browsers apply it.
     */
    public static function covers(string $pattern, string $hostname): bool
    {
        $pattern = strtolower($pattern);
        $hostname = strtolower($hostname);

        if (!str_starts_with($pattern, '*.')) {
            return $pattern === $hostname;
        }

        $dot = strpos($hostname, '.');

        return $dot !== false && substr($hostname, $dot) === substr($pattern, 1) && $dot > 0;
    }

    /**
     * Judge the outcome. Pure, for tests.
     *
     * @param array<string, mixed>|null $leaf openssl_x509_parse() of the site's certificate
     */
    /**
     * @param list<string> $expectedNames
     */
    public function evaluate(string $host, int $port, bool $verified, ?string $verifyError, ?array $leaf, ?string $protocol, ?string $connectError, int $now, array $expectedNames = []): CheckResult
    {
        $key = 'ssl:' . $host . ':' . $port;
        $label = $port === 443 ? $host : "$host:$port";

        if ($leaf === null) {
            return new CheckResult($key, $label, HealthStatus::Critical, "Couldn't get a certificate: " . ($connectError ?? $verifyError ?? 'no TLS response') . '.');
        }

        $validFrom = (int) ($leaf['validFrom_time_t'] ?? 0);
        $validTo = (int) ($leaf['validTo_time_t'] ?? 0);
        $daysLeft = round(($validTo - $now) / 86400, 1);
        $subject = (string) ($leaf['subject']['CN'] ?? '');
        $issuer = (string) ($leaf['issuer']['O'] ?? $leaf['issuer']['CN'] ?? 'unknown issuer');
        $names = $this->names($leaf);
        $details = [
            'subject' => $subject,
            'issuer' => $issuer,
            'names' => $names,
            'valid_from' => gmdate('Y-m-d H:i', $validFrom) . ' UTC',
            'valid_to' => gmdate('Y-m-d H:i', $validTo) . ' UTC',
            'protocol' => $protocol,
            'verify_error' => $verifyError,
        ];
        $expiry = 'expires ' . gmdate('Y-m-d', $validTo);

        if (!$verified) {
            $reason = match (true) {
                $validTo < $now => 'expired on ' . gmdate('Y-m-d', $validTo),
                $validFrom > $now => 'not valid until ' . gmdate('Y-m-d', $validFrom),
                $verifyError !== null && str_contains($verifyError, 'did not match') => 'issued for ' . implode(', ', $names ?: [$subject]) . ", not $host",
                $leaf['subject'] == $leaf['issuer'] => 'self-signed, so browsers won\'t trust it',
                default => "not trusted (issued by $issuer): the site may be missing an intermediate certificate, or the issuer isn't a trusted CA",
            };

            return new CheckResult($key, $label, HealthStatus::Critical, "Invalid certificate: $reason.", $daysLeft, 'days', $details);
        }

        $summary = "Valid, $expiry (" . ($daysLeft >= 1 ? floor($daysLeft) . ' days' : 'less than a day') . "), issued by $issuer.";
        $status = $daysLeft <= $this->warningDays ? HealthStatus::Warning : HealthStatus::Ok;

        if ($status !== HealthStatus::Ok) {
            $summary .= " Expires within {$this->warningDays} days: renew it soon.";
        }

        $uncovered = array_values(array_filter(
            $expectedNames,
            fn (string $name) => !array_filter($names ?: [$subject], fn (string $pattern) => self::covers($pattern, $name)),
        ));

        if ($uncovered !== []) {
            $status = HealthStatus::Warning;
            $summary .= ' Doesn\'t cover ' . implode(', ', $uncovered) . '.';
            $details['uncovered'] = $uncovered;
        }

        return new CheckResult($key, $label, $status, $summary, $daysLeft, 'days', $details);
    }

    /**
     * @return array{0: array{leaf: array<string, mixed>, protocol: ?string}|null, 1: ?string}
     */
    private function connect(string $address, string $host, int $port, bool $verify): array
    {
        $errors = [];
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => false,
            'cafile' => $this->caFile ?? $this->certificates->systemBundle(),
        ]]);

        // Collect warnings instead of letting them become exceptions: they carry the TLS error.
        set_error_handler(function (int $level, string $message) use (&$errors) {
            $errors[] = $message;

            return true;
        });

        try {
            // Connect to the server's address; peer_name above sends $host as SNI and verifies against it.
            $target = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[$address]" : $address;
            $stream = stream_socket_client("ssl://$target:$port", $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            return [null, $this->explain($errors, $errstr)];
        }

        $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        $protocol = stream_get_meta_data($stream)['crypto']['protocol'] ?? null;
        fclose($stream);
        $leaf = $certificate === null ? false : openssl_x509_parse($certificate);

        return $leaf === false ? [null, 'no certificate received'] : [['leaf' => $leaf, 'protocol' => $protocol], null];
    }

    /**
     * @param list<string> $errors
     */
    private function explain(array $errors, string $errstr): string
    {
        foreach ($errors as $error) {
            if (str_contains($error, 'did not match')) {
                return (string) preg_replace('/^stream_socket_client\(\): /', '', $error);
            }
        }

        foreach ($errors as $error) {
            if (preg_match('/Unable to connect to .*\((.+)\)/', $error, $m) && $m[1] !== 'Unknown error') {
                return $m[1];
            }
        }

        foreach ($errors as $error) {
            if (preg_match('/error:[0-9A-F]+:[^:]*::?([^\n]+)/', $error, $m)) {
                return trim($m[1]);
            }
        }

        return $errstr !== '' ? $errstr : 'TLS connection failed';
    }

    /**
     * @param array<string, mixed> $leaf
     * @return list<string>
     */
    private function names(array $leaf): array
    {
        preg_match_all('/DNS:([^,\s]+)/', (string) ($leaf['extensions']['subjectAltName'] ?? ''), $matches);

        return array_values(array_unique($matches[1]));
    }
}
