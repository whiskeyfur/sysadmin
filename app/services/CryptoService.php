<?php

namespace App\Services;

use App\Exceptions\DecryptionException;
use SodiumException;

/**
 * libsodium primitives used by every key service (CLAUDE.md rules 2, 3, 8).
 *
 * Ciphertext is XChaCha20-Poly1305 with a fresh random nonce, stored as
 * base64(nonce . ciphertext). Every call takes a context string that is
 * bound in as associated data, so a ciphertext copied into another row
 * or column fails to decrypt instead of being accepted.
 */
class CryptoService
{
    public const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /**
     * Argon2id cost for new password-derived keys. Existing users keep the
     * limits stored on their row, so these can be raised later.
     */
    public function __construct(
        private readonly int $opsLimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private readonly int $memLimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ) {
    }

    public function opsLimit(): int
    {
        return $this->opsLimit;
    }

    public function memLimit(): int
    {
        return $this->memLimit;
    }

    public function generateKey(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }

    public function deriveKey(string $password, string $salt, int $opsLimit, int $memLimit): string
    {
        return sodium_crypto_pwhash(
            self::KEY_BYTES,
            $password,
            $salt,
            $opsLimit,
            $memLimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    public function encrypt(string $plaintext, string $key, string $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key);

        return $this->encode($nonce . $ciphertext);
    }

    /**
     * @throws DecryptionException when the key or context is wrong, or the data was tampered with.
     */
    public function decrypt(string $encoded, string $key, string $context): string
    {
        $raw = $this->decode($encoded);

        if ($raw === null || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new DecryptionException('Ciphertext is malformed.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, self::NONCE_BYTES),
                $context,
                substr($raw, 0, self::NONCE_BYTES),
                $key,
            );
        } catch (SodiumException $e) {
            throw new DecryptionException('Ciphertext could not be decrypted.', 0, $e);
        }

        if ($plaintext === false) {
            throw new DecryptionException('Ciphertext could not be decrypted.');
        }

        return $plaintext;
    }

    public function encode(string $binary): string
    {
        return sodium_bin2base64($binary, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decode(string $encoded): ?string
    {
        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            return null;
        }
    }

    /**
     * Overwrite secrets in memory and null the variables. Best effort: PHP
     * may still hold other copies of a string that was shared or copied.
     */
    public function wipe(?string &...$secrets): void
    {
        foreach ($secrets as &$secret) {
            if ($secret !== null) {
                sodium_memzero($secret);
            }

            $secret = null;
        }
    }
}
