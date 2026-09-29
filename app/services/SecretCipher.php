<?php

namespace App\Services;

use App\Exceptions\DecryptionException;
use RuntimeException;
use SodiumException;

/**
 * Encrypts the few secrets that must not sit in the database as plain text
 * (authenticator secrets), with a key derived from APP_KEY in .env. A copy
 * of the database alone is then not enough to generate codes.
 *
 * XChaCha20-Poly1305, stored as base64(nonce . ciphertext). Each value is
 * bound to a context string (for example "totp-secret:<user id>"), so a
 * value copied to another row fails to decrypt.
 */
class SecretCipher
{
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    private readonly string $key;

    /**
     * @param string|null $appKey APP_KEY as written in .env ("base64:..."); defaults to the environment.
     */
    public function __construct(?string $appKey = null)
    {
        $appKey ??= (string) _env('APP_KEY', '');
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

        if (!is_string($raw) || strlen($raw) < 16) {
            throw new RuntimeException('APP_KEY is missing or too short. Run `php leaf key:generate`.');
        }

        $this->key = hash_hkdf('sha256', $raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'sys:secret-cipher:v1');
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key));
    }

    /**
     * @throws DecryptionException if the app key or context is wrong, or the value was tampered with.
     */
    public function decrypt(string $encoded, string $context): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new DecryptionException('Encrypted value is malformed.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, self::NONCE_BYTES),
                $context,
                substr($raw, 0, self::NONCE_BYTES),
                $this->key,
            );
        } catch (SodiumException $e) {
            throw new DecryptionException('Encrypted value could not be decrypted.', 0, $e);
        }

        if ($plaintext === false) {
            throw new DecryptionException('Encrypted value could not be decrypted.');
        }

        return $plaintext;
    }
}
