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
 *
 * A server that doesn't send its intermediate certificate fails
 * verification here (and in curl, PHP, Java, some phones), though browsers
 * fetch the missing intermediate from the address in the certificate (AIA
 * "CA Issuers") and show the site fine. So when verification fails, the
 * intermediate is fetched the same way (plain HTTP to a public address,
 * 5 seconds, 20 KB; never a private or local one) and the chain verified
 * with it as an untrusted link (openssl_x509_checkpurpose): if that works,
 * it's noted in the result (what to fix, and that the check had to
 * download the intermediate), not "not trusted"; the admin setting
 * ssl_missing_intermediate makes it a warning or critical.
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
        private readonly ?\Closure $fetch = null,
        private readonly HealthStatus $missingIntermediate = HealthStatus::Ok,
    ) {
    }

    /**
     * Intermediates fetched by this checker (one monitoring run), by URL.
     *
     * @var array<string, ?string>
     */
    private array $fetched = [];

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
        $chain = $verified === null && $unverified !== null && ($verifyError === null || !str_contains($verifyError, 'did not match'))
            ? $this->completeChain($unverified['pem'], $unverified['leaf'])
            : null;

        $result = $this->evaluate(
            $host,
            $port,
            $verified !== null,
            $verifyError,
            $info === null ? null : $info['leaf'],
            $info === null ? null : $info['protocol'],
            $connectError,
            $this->clock->now()->getTimestamp(),
            $expectedNames,
            $chain,
        );

        // Where the certificate came from: the host contacted, and the address the connection reached (behind
        // a load balancer or round-robin DNS, one name can be several servers serving their own copies).
        return new CheckResult($result->key, $result->label, $result->status, $result->summary, $result->value, $result->unit, $result->details + [
            'contacted' => $connectTo,
            'address' => $info['address'] ?? null,
        ]);
    }

    /**
     * The hostnames a served certificate lists (its SAN DNS names, or the
     * subject CN if it has none), or null if none could be read. The
     * certificate isn't verified here: this only learns names to monitor,
     * and check() judges the certificate.
     *
     * @return list<string>|null
     */
    public function certificateNames(string $host, int $port = 443, ?string $connectTo = null): ?array
    {
        [$info] = $this->connect($connectTo ?? $host, $host, $port, false);

        if ($info === null) {
            return null;
        }

        $names = $this->names($info['leaf']) ?: array_filter([(string) ($info['leaf']['subject']['CN'] ?? '')]);

        return array_values(array_unique(array_map('strtolower', $names)));
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
     * @param list<string> $expectedNames
     * @param array{issuer: string, url: string}|null $chain the intermediate the server should have sent, when fetching it
     *                                                    made the chain verify
     */
    public function evaluate(string $host, int $port, bool $verified, ?string $verifyError, ?array $leaf, ?string $protocol, ?string $connectError, int $now, array $expectedNames = [], ?array $chain = null): CheckResult
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
        $incomplete = false;

        // The chain verified once the missing intermediate was fetched: valid (as browsers see it), if the name fits.
        if (!$verified && $chain !== null && $validTo >= $now && $validFrom <= $now
            && array_filter($names ?: [$subject], fn (string $pattern) => self::covers($pattern, $host)) !== []) {
            $verified = true;
            $incomplete = true;
            $details['missing_intermediate'] = $chain;
        }

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

        if ($daysLeft <= $this->warningDays) {
            $summary .= " Expires within {$this->warningDays} days: renew it soon.";
        }

        if ($incomplete && $chain !== null) {
            $status = HealthStatus::worst([$status, $this->missingIntermediate]);
            $summary .= " Note: the server doesn't send its intermediate certificate ({$chain['issuer']}); this check downloaded it from {$chain['url']}, as browsers do. curl, PHP, Java and some phones reject the site: send the full chain (in Apache, SSLCertificateFile pointing at the fullchain file).";
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
     * @return array{0: array{leaf: array<string, mixed>, protocol: ?string, pem: string}|null, 1: ?string}
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
        // The address actually reached ("1.2.3.4:443", "[2001:db8::1]:443"), without the port.
        $peer = stream_socket_get_name($stream, true);
        $reached = $peer === false ? null : (string) preg_replace('/^\[?(.*?)\]?:\d+$/', '$1', $peer);
        fclose($stream);
        $leaf = $certificate === null ? false : openssl_x509_parse($certificate);
        $pem = '';

        if ($certificate !== null) {
            openssl_x509_export($certificate, $pem);
        }

        return $leaf === false ? [null, 'no certificate received'] : [['leaf' => $leaf, 'protocol' => $protocol, 'pem' => $pem, 'address' => $reached], null];
    }

    /**
     * Fetch the intermediate named in the certificate (AIA "CA Issuers") and verify the chain with it as an
     * untrusted link: the intermediate that was missing, or null if that doesn't make a trusted chain.
     *
     * @param array<string, mixed> $leaf
     * @return array{issuer: string, url: string}|null
     */
    private function completeChain(string $leafPem, array $leaf): ?array
    {
        if ($leafPem === '' || preg_match('#CA Issuers - URI:(\S+)#', (string) ($leaf['extensions']['authorityInfoAccess'] ?? ''), $m) !== 1) {
            return null;
        }

        $url = $m[1];
        $body = array_key_exists($url, $this->fetched) ? $this->fetched[$url] : ($this->fetched[$url] = $this->fetch !== null ? ($this->fetch)($url) : $this->download($url));

        if ($body === null || $body === '') {
            return null;
        }

        $pem = str_contains($body, '-----BEGIN CERTIFICATE-----') ? $body : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($body), 64, "\n") . "-----END CERTIFICATE-----\n";
        $intermediate = @openssl_x509_parse($pem);

        if ($intermediate === false) {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'sys-aia-');

        if ($file === false) {
            return null;
        }

        try {
            file_put_contents($file, $pem);
            $ok = @openssl_x509_checkpurpose($leafPem, X509_PURPOSE_SSL_SERVER, [$this->caFile ?? $this->certificates->systemBundle()], $file);
        } finally {
            @unlink($file);
        }

        return $ok === true ? ['issuer' => (string) ($intermediate['subject']['CN'] ?? $intermediate['subject']['O'] ?? 'intermediate'), 'url' => $url] : null;
    }

    /**
     * GET a CA Issuers URL: plain http, a public address only (a certificate mustn't make the app reach inside the
     * network), connecting to the address checked, 5 seconds, 20 KB.
     */
    private function download(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http' || empty($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $ips = gethostbynamel($parts['host']) ?: [];

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        if ($ips === []) {
            return null;
        }

        $port = (int) ($parts['port'] ?? 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $context = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true, 'header' => 'Host: ' . $parts['host'] . "\r\nUser-Agent: sys-ssl-check\r\n"]]);

        set_error_handler(fn () => true);
        $body = false;
        $headers = [];

        try {
            // The address checked above, so a DNS answer changing in between can't point it elsewhere.
            $stream = fopen("http://{$ips[0]}:$port$path", 'rb', false, $context);

            if (is_resource($stream)) {
                $body = stream_get_contents($stream, 20000);
                // The response headers from the stream itself: $http_response_header is deprecated as of PHP 8.4,
                // and http_get_last_response_headers() doesn't exist before it.
                $headers = (array) (stream_get_meta_data($stream)['wrapper_data'] ?? []);
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }

        if (!is_string($body)) {
            return null;
        }

        return str_contains(implode("\n", array_map('strval', $headers)), ' 200 ') ? $body : null;
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
