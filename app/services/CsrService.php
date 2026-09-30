<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Models\CsrRequest;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use OpenSSLAsymmetricKey;
use Psr\Clock\ClockInterface;

/**
 * The CSR tool (SSL › CSR, admins): a certificate signing request to replace a certificate, with the same
 * names and subject by default. The certificate comes from a monitored entry (the one its server presents
 * now, fetched unverified) or is pasted. The private key is the existing one, pasted (checked against the
 * certificate, used and not kept), or a new one made here (RSA 2048/3072/4096 or EC P-256/P-384, the old
 * certificate's kind by default), kept encrypted (SecretCipher, csr-private-key:<id>) so it can be
 * downloaded later with a fresh check of the admin's password or passkey, and deleted.
 *
 * openssl is driven with a config file of our own for each request (subjectAltName goes in the
 * request's extensions that way, and it doesn't depend on the system's openssl.cnf, which PHP on
 * Windows often can't find).
 */
class CsrService
{
    /**
     * Key choices: type, size (RSA bits, EC curve bits).
     *
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    public const KEYS = [
        'rsa-2048' => ['rsa', 2048, 'RSA 2048'],
        'rsa-3072' => ['rsa', 3072, 'RSA 3072'],
        'rsa-4096' => ['rsa', 4096, 'RSA 4096'],
        'ec-256' => ['ec', 256, 'EC P-256'],
        'ec-384' => ['ec', 384, 'EC P-384'],
    ];

    private const CURVES = [256 => 'prime256v1', 384 => 'secp384r1'];

    public const MAX_NAMES = 100;

    /**
     * Subject fields beyond the common name, as openssl names them, with their labels.
     *
     * @var array<string, string>
     */
    public const SUBJECT = ['O' => 'Organisation', 'OU' => 'Department', 'L' => 'City', 'ST' => 'State or province', 'C' => 'Country (2 letters)', 'emailAddress' => 'Email'];

    public function __construct(
        private readonly SslCheckService $checker = new SslCheckService(),
        private readonly SecretCipher $cipher = new SecretCipher(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * A certificate's details, for the form: its common name and names, subject, key, issuer and dates.
     *
     * @return array{pem: string, common_name: string, names: list<string>, subject: array<string, string>, key: string, issuer: string, valid_to: ?Carbon, serial: string, fingerprint: string}
     *
     * @throws DomainException when it isn't a certificate
     */
    public function describe(string $pem): array
    {
        $pem = trim($pem);

        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) || ($x509 = @openssl_x509_read($m[0])) === false) {
            throw new DomainException('That isn\'t a certificate: paste the PEM text, from -----BEGIN CERTIFICATE----- to -----END CERTIFICATE-----.');
        }

        $pem = $m[0];
        $info = (array) openssl_x509_parse($x509);
        $subject = (array) ($info['subject'] ?? []);
        $names = [];

        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            [$kind, $value] = array_pad(explode(':', trim($entry), 2), 2, '');

            if (in_array($kind, ['DNS', 'IP Address'], true) && $value !== '') {
                $names[] = strtolower($value);
            }
        }

        $cn = strtolower((string) (is_array($subject['CN'] ?? null) ? $subject['CN'][0] : ($subject['CN'] ?? '')));
        $names = array_values(array_unique(array_merge($cn !== '' ? [$cn] : [], $names)));
        $key = openssl_pkey_get_details((openssl_pkey_get_public($x509)) ?: throw new DomainException('Its public key can\'t be read.')) ?: [];
        $fields = [];

        foreach (array_keys(self::SUBJECT) as $field) {
            if (isset($subject[$field])) {
                $fields[$field] = (string) (is_array($subject[$field]) ? $subject[$field][0] : $subject[$field]);
            }
        }

        return [
            'pem' => $pem,
            'common_name' => $cn !== '' ? $cn : ($names[0] ?? ''),
            'names' => $names,
            'subject' => $fields,
            'key' => $this->keyChoice($key),
            'issuer' => (string) ($info['issuer']['CN'] ?? $info['issuer']['O'] ?? ''),
            'valid_to' => isset($info['validTo_time_t']) ? Carbon::createFromTimestamp((int) $info['validTo_time_t']) : null,
            'serial' => (string) ($info['serialNumberHex'] ?? ''),
            'fingerprint' => implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($x509, 'sha256')), 2)),
        ];
    }

    /**
     * The certificate a monitored entry is served with now (its first place), described.
     *
     * @return array<string, mixed> see describe()
     *
     * @throws DomainException when it can't be fetched
     */
    public function fromMonitored(User $admin, SslCertificate $certificate): array
    {
        $this->requireAdmin($admin);
        $binding = SslBinding::query()->with('server')->where('certificate_id', $certificate->id)->orderBy('id')->first();

        if (!$binding instanceof SslBinding) {
            throw new DomainException("{$certificate->name} isn't served anywhere this app checks: paste the certificate instead.");
        }

        $pem = $this->checker->certificatePem($certificate->primaryHostname(), $binding->port, $binding->server?->hostname);

        if ($pem === null) {
            throw new DomainException("Couldn't fetch the certificate from " . ($binding->server->hostname ?? $certificate->primaryHostname()) . ":{$binding->port}: paste it instead.");
        }

        return $this->describe($pem);
    }

    /**
     * Make the request (and a new key, unless an existing one is given) and keep it.
     *
     * @param array{common_name?: mixed, names?: mixed, subject?: mixed, key?: mixed, private_key?: mixed, certificate_pem?: mixed, certificate_id?: mixed} $input
     *
     * @throws DomainException with a user-facing message (nothing made)
     * @throws AuthorizationException for anyone but an admin
     */
    public function create(User $admin, array $input): CsrRequest
    {
        $this->requireAdmin($admin);
        $cn = strtolower(trim((string) ($input['common_name'] ?? '')));
        $names = $this->names($cn, (string) ($input['names'] ?? ''));
        $subject = $this->subject((array) ($input['subject'] ?? []));
        $providedPem = trim((string) ($input['private_key'] ?? ''));
        $certificatePem = trim((string) ($input['certificate_pem'] ?? ''));

        if (!self::isName($cn) || preg_match('/^[0-9.:]+$/', $cn) === 1) {
            throw new DomainException('Give the common name: the main host name, e.g. www.example.com (or *.example.com).');
        }

        $config = $this->config($names);

        try {
            if ($providedPem !== '') {
                $key = @openssl_pkey_get_private($providedPem);

                if ($key === false) {
                    throw new DomainException('That private key can\'t be read: paste it as PEM, unencrypted (-----BEGIN PRIVATE KEY----- or -----BEGIN RSA/EC PRIVATE KEY-----).');
                }

                if ($certificatePem !== '' && @openssl_x509_check_private_key($certificatePem, $key) !== true) {
                    throw new DomainException('That private key doesn\'t belong to the certificate: its public key is different. Paste the certificate\'s own key, or leave the field empty for a new one.');
                }

                [$type, $size] = $this->keyOf($key);
            } else {
                [$type, $size] = self::KEYS[(string) ($input['key'] ?? '')] ?? throw new DomainException('Choose the kind of key.');
                $key = openssl_pkey_new($type === 'rsa'
                    ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $size, 'config' => $config]
                    : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => self::CURVES[$size], 'config' => $config]);

                if ($key === false) {
                    throw new DomainException('The key couldn\'t be made: ' . (openssl_error_string() ?: 'openssl failed') . '.');
                }
            }

            $dn = ['commonName' => $cn] + array_filter([
                'organizationName' => $subject['O'] ?? null, 'organizationalUnitName' => $subject['OU'] ?? null, 'localityName' => $subject['L'] ?? null,
                'stateOrProvinceName' => $subject['ST'] ?? null, 'countryName' => $subject['C'] ?? null, 'emailAddress' => $subject['emailAddress'] ?? null,
            ]);
            $request = openssl_csr_new($dn, $key, ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req']);

            if (!$request instanceof \OpenSSLCertificateSigningRequest || !openssl_csr_export($request, $csr)) {
                throw new DomainException('The request couldn\'t be made: ' . (openssl_error_string() ?: 'openssl failed') . '.');
            }

            $privatePem = null;

            if ($providedPem === '' && !openssl_pkey_export($key, $privatePem, null, ['config' => $config])) {
                throw new DomainException('The new key couldn\'t be written out: ' . (openssl_error_string() ?: 'openssl failed') . '.');
            }
        } finally {
            @unlink($config);
        }

        $request = CsrRequest::query()->create([
            'user_id' => $admin->id, 'certificate_id' => is_numeric($input['certificate_id'] ?? null) ? (int) $input['certificate_id'] : null,
            'common_name' => $cn, 'names' => $names, 'subject' => $subject, 'key_type' => $type, 'key_size' => $size,
            'key_source' => $providedPem === '' ? 'new' : 'provided', 'csr' => (string) $csr, 'created_at' => Carbon::instance($this->clock->now()),
        ]);

        if (is_string($privatePem)) {
            $request->private_key = $this->cipher->encrypt($privatePem, "csr-private-key:{$request->id}");
            $request->save();
        }

        return $request;
    }

    /**
     * The new key made with a request (PEM), for downloading.
     *
     * @throws DomainException when the request has none (an existing key was used)
     */
    public function privateKey(User $admin, CsrRequest $request): string
    {
        $this->requireAdmin($admin);

        if ($request->private_key === null) {
            throw new DomainException('This request used an existing private key, which isn\'t kept here.');
        }

        return $this->cipher->decrypt($request->private_key, "csr-private-key:{$request->id}");
    }

    public function delete(User $admin, CsrRequest $request): void
    {
        $this->requireAdmin($admin);
        $request->delete();
    }

    /**
     * Whether a name can go in a certificate: a host name (a leading "*." allowed) or an IP address.
     */
    public static function isName(string $name): bool
    {
        return filter_var($name, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(\*\.)?(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/i', $name) === 1;
    }

    /**
     * The names to ask for: the common name first, then the others (one per line, or commas/spaces).
     *
     * @return list<string>
     *
     * @throws DomainException
     */
    private function names(string $cn, string $text): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => strtolower(trim($n)), preg_split('/[\s,]+/', $text) ?: []))));
        $names = array_values(array_unique(array_merge($cn !== '' ? [$cn] : [], $names)));

        foreach ($names as $name) {
            if (!self::isName($name)) {
                throw new DomainException("\"$name\" isn't a host name or IP address.");
            }
        }

        if (count($names) > self::MAX_NAMES) {
            throw new DomainException('At most ' . self::MAX_NAMES . ' names.');
        }

        return $names;
    }

    /**
     * @param array<mixed> $input
     * @return array<string, string>
     *
     * @throws DomainException
     */
    private function subject(array $input): array
    {
        $subject = [];

        foreach (array_keys(self::SUBJECT) as $field) {
            $value = trim((string) ($input[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new DomainException(self::SUBJECT[$field] . ': at most 64 characters, one line.');
            }

            if ($field === 'C' && preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
                throw new DomainException('Country: its two-letter code, e.g. US.');
            }

            $subject[$field] = $field === 'C' ? strtoupper($value) : $value;
        }

        return $subject;
    }

    /**
     * An openssl config for one request, with its names as subjectAltName (a temporary file; the caller
     * deletes it).
     *
     * @param list<string> $names
     */
    private function config(array $names): string
    {
        $alt = implode(',', array_map(fn ($n) => (filter_var($n, FILTER_VALIDATE_IP) !== false ? 'IP:' : 'DNS:') . $n, $names));
        $file = (string) tempnam(sys_get_temp_dir(), 'csr');
        // default_bits even for EC keys: PHP's openssl_pkey_new() refuses a config without it.
        file_put_contents($file, "[req]\ndefault_bits = 2048\ndefault_md = sha256\ndistinguished_name = dn\nreq_extensions = v3_req\nprompt = no\n\n[dn]\n\n[v3_req]\nsubjectAltName = $alt\n");

        return $file;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function keyOf(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key) ?: [];

        return match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => ['rsa', (int) $details['bits']],
            OPENSSL_KEYTYPE_EC => ['ec', (int) $details['bits']],
            default => throw new DomainException('Only RSA and EC keys are supported.'),
        };
    }

    /**
     * The KEYS choice matching a certificate's key (for the form's default).
     *
     * @param array<string, mixed> $details
     */
    private function keyChoice(array $details): string
    {
        $bits = (int) ($details['bits'] ?? 0);

        return match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_EC => $bits >= 384 ? 'ec-384' : 'ec-256',
            OPENSSL_KEYTYPE_RSA => $bits >= 4096 ? 'rsa-4096' : ($bits >= 3072 ? 'rsa-3072' : 'rsa-2048'),
            default => 'rsa-2048',
        };
    }

    /**
     * @throws AuthorizationException
     */
    private function requireAdmin(User $user): void
    {
        if (!$user->isAdmin()) {
            throw new AuthorizationException('Only admins can make certificate requests.');
        }
    }
}
