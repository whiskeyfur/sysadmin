<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\Passkey;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use lbuchs\WebAuthn\Attestation\AttestationObject;
use lbuchs\WebAuthn\Attestation\AuthenticatorData;

/**
 * Passkeys and hardware security keys (WebAuthn). lbuchs/webauthn parses
 * the authenticator's data (CBOR, COSE keys, attestation formats); the
 * ceremony checks are done here, because the library only accepts http
 * for the host "localhost" (not sys.localhost, which browsers also treat
 * as secure) and matches the host by suffix without a dot boundary.
 *
 * Checked: the challenge (one use, from the session), the exact origin of
 * the page (https, or http on localhost/*.localhost only), the relying
 * party (the page's host, stored with each passkey), user presence and
 * user verification (a PIN or biometric: a passkey alone signs in, so a
 * stolen key alone mustn't), the signature, and the signature counter
 * (a counter going backwards means a cloned key).
 */
class PasskeyService
{
    public const TIMEOUT_MS = 120000;

    private const FORMATS = ['android-key', 'android-safetynet', 'apple', 'fido-u2f', 'none', 'packed', 'tpm'];

    public function __construct(private readonly ?string $appKey = null)
    {
    }

    /**
     * The relying party and origin for a request: host without port, and
     * scheme://host[:port]. Null when passkeys can't work there (plain http
     * on anything but localhost).
     *
     * @return array{rp_id: string, origin: string}|null
     */
    public static function site(string $hostHeader, bool $https): ?array
    {
        $hostHeader = strtolower(trim($hostHeader));

        if (preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/', $hostHeader, $m) !== 1 || str_starts_with($m[1], '[') || filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
            return null; // WebAuthn needs a domain name, not an IP address
        }

        $local = $m[1] === 'localhost' || str_ends_with($m[1], '.localhost');

        if (!$https && !$local) {
            return null;
        }

        return ['rp_id' => $m[1], 'origin' => ($https ? 'https' : 'http') . "://$hostHeader"];
    }

    public static function challenge(): string
    {
        return random_bytes(32);
    }

    /**
     * Options for navigator.credentials.create(), binary values base64url.
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(User $user, string $rpId, string $challenge): array
    {
        return [
            'challenge' => self::encode($challenge),
            'rp' => ['id' => $rpId, 'name' => 'sys'],
            'user' => ['id' => self::encode(self::userHandle($user)), 'name' => $user->username, 'displayName' => $user->username],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -8], ['type' => 'public-key', 'alg' => -257]],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
            'excludeCredentials' => Passkey::query()->where('user_id', $user->id)->where('rp_id', $rpId)->pluck('credential_id')
                ->map(fn (string $id) => ['type' => 'public-key', 'id' => $id])->values()->all(),
        ];
    }

    /**
     * Check a new credential and store it.
     *
     * @param array<string, mixed> $response the browser's credential: response.clientDataJSON and
     *                                       response.attestationObject, base64url
     *
     * @throws DomainException when it doesn't check out
     */
    public function register(User $user, array $site, string $challenge, array $response, string $name): Passkey
    {
        $name = trim($name) === '' ? 'Passkey' : mb_substr(trim($name), 0, 100);
        $clientDataJson = self::decode((string) ($response['clientDataJSON'] ?? ''));
        $this->checkClientData($clientDataJson, 'webauthn.create', $challenge, $site['origin']);

        try {
            $attestation = new AttestationObject(self::decode((string) ($response['attestationObject'] ?? '')), self::FORMATS);

            if (!$attestation->validateRpIdHash(hash('sha256', $site['rp_id'], true)) || !$attestation->validateAttestation(hash('sha256', $clientDataJson, true))) {
                throw new DomainException('The passkey was made for another site, or its attestation is invalid.');
            }

            $data = $attestation->getAuthenticatorData();

            if (!$data->getUserPresent() || !$data->getUserVerified()) {
                throw new DomainException('The key must check it\'s you (a PIN, fingerprint or face).');
            }

            $credentialId = $data->getCredentialId();
            $publicKey = $data->getPublicKeyPem();
            $aaguid = bin2hex((string) $data->getAAGUID());
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            throw new DomainException("The passkey couldn't be read: {$e->getMessage()}");
        }

        if (Passkey::query()->where('credential_hash', hash('sha256', $credentialId))->exists()) {
            throw new DomainException('That passkey is already registered.');
        }

        return Passkey::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'credential_id' => self::encode($credentialId),
            'credential_hash' => hash('sha256', $credentialId),
            'public_key' => $publicKey,
            'sign_count' => $data->getSignCount(),
            'rp_id' => $site['rp_id'],
            'aaguid' => trim($aaguid, '0') === '' ? null : $aaguid,
            'backup_eligible' => (bool) $data->getIsBackupEligible(),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Options for navigator.credentials.get(). With a username, its
     * passkeys are listed (security keys need that); for a username that
     * doesn't exist or has none, a made-up id stands in, so the answer
     * doesn't tell whether it exists. Without, the browser offers the
     * passkeys it has for the site.
     *
     * @return array<string, mixed>
     */
    public function assertionOptions(string $rpId, string $challenge, ?string $username = null): array
    {
        $allow = [];

        if ($username !== null && $username !== '') {
            $user = User::query()->where('username', $username)->first();
            $ids = $user instanceof User ? Passkey::query()->where('user_id', $user->id)->where('rp_id', $rpId)->pluck('credential_id')->all() : [];
            $allow = $ids !== [] ? $ids : [self::encode(substr(hash_hmac('sha256', strtolower($username) . "\0$rpId", $this->fakeIdKey(), true), 0, 16))];
        }

        return [
            'challenge' => self::encode($challenge),
            'rpId' => $rpId,
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => array_map(fn (string $id) => ['type' => 'public-key', 'id' => $id], $allow),
        ];
    }

    /**
     * Check a sign-in (or re-confirmation) with a passkey; the passkey that
     * signed, with its counter and last use updated.
     *
     * @param array<string, mixed> $credential the browser's credential: id, and response.clientDataJSON,
     *                                         response.authenticatorData, response.signature, response.userHandle
     * @param User|null $expected when confirming: the passkey must be this user's
     *
     * @throws InvalidCredentialsException for anything that doesn't check out
     */
    public function verify(array $site, string $challenge, array $credential, ?User $expected = null): Passkey
    {
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $rawId = self::decode((string) ($credential['id'] ?? ''));
        $passkey = $rawId === '' ? null : Passkey::query()->where('credential_hash', hash('sha256', $rawId))->first();

        if (!$passkey instanceof Passkey || $passkey->rp_id !== $site['rp_id'] || ($expected !== null && $passkey->user_id !== $expected->id)) {
            throw new InvalidCredentialsException('Unknown passkey.');
        }

        $handle = (string) ($response['userHandle'] ?? '');

        if ($handle !== '' && !hash_equals(self::userHandle($passkey->user_id), self::decode($handle))) {
            throw new InvalidCredentialsException('The passkey belongs to someone else.');
        }

        $clientDataJson = self::decode((string) ($response['clientDataJSON'] ?? ''));
        $authenticatorData = self::decode((string) ($response['authenticatorData'] ?? ''));

        try {
            $this->checkClientData($clientDataJson, 'webauthn.get', $challenge, $site['origin']);
            $data = new AuthenticatorData($authenticatorData);
        } catch (DomainException | \lbuchs\WebAuthn\WebAuthnException $e) {
            throw new InvalidCredentialsException($e->getMessage());
        }

        if (!hash_equals(hash('sha256', $site['rp_id'], true), $data->getRpIdHash()) || !$data->getUserPresent() || !$data->getUserVerified()) {
            throw new InvalidCredentialsException('The passkey was for another site, or didn\'t check it\'s you.');
        }

        if (!$this->signatureValid($authenticatorData . hash('sha256', $clientDataJson, true), self::decode((string) ($response['signature'] ?? '')), $passkey->public_key)) {
            throw new InvalidCredentialsException('Invalid signature.');
        }

        $count = $data->getSignCount();

        if (($count !== 0 || $passkey->sign_count !== 0) && $count <= $passkey->sign_count) {
            throw new InvalidCredentialsException('The key\'s counter went backwards: it may have been copied.');
        }

        $passkey->sign_count = $count;
        $passkey->last_used_at = Carbon::now();
        $passkey->save();

        return $passkey;
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $base64url): string
    {
        $binary = base64_decode(strtr($base64url, '-_', '+/'), true);

        return $binary === false ? '' : $binary;
    }

    /**
     * The WebAuthn user handle: the user id, with no personal data.
     */
    public static function userHandle(User|int $user): string
    {
        return 'sys-user:' . ($user instanceof User ? $user->id : $user);
    }

    /**
     * @throws DomainException
     */
    private function checkClientData(string $json, string $type, string $challenge, string $origin): void
    {
        $data = json_decode($json, true);

        if (!is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new DomainException('Invalid response from the browser.');
        }

        if (!hash_equals($challenge, self::decode((string) ($data['challenge'] ?? '')))) {
            throw new DomainException('The request expired or was already used. Try again.');
        }

        if (($data['origin'] ?? null) !== $origin) {
            throw new DomainException('The passkey was used on another site.');
        }
    }

    private function signatureValid(string $signed, string $signature, string $publicKeyPem): bool
    {
        // Ed25519 (COSE -8): OpenSSL builds often can't, sodium can.
        if (preg_match('/BEGIN PUBLIC KEY-+\s+([^-]+)-+END PUBLIC KEY/', $publicKeyPem, $m) === 1) {
            $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
            $prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

            if (is_string($der) && strlen($der) === 44 && str_starts_with($der, $prefix)) {
                return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $signed, substr($der, 12));
            }
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        return $key !== false && openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * A key for the made-up ids, from the app key: the same for a username every time.
     */
    private function fakeIdKey(): string
    {
        return hash_hkdf('sha256', ($this->appKey ?? (string) _env('APP_KEY', '')) ?: 'sys', 32, 'sys:passkey-fake-ids');
    }
}
