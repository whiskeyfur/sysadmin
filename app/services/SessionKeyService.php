<?php

namespace App\Services;

use App\DTOs\SealedMasterKey;
use App\Exceptions\DecryptionException;

/**
 * Keeps the master key off disk between requests (CLAUDE.md rule 7).
 *
 * At login, seal() encrypts the master key under a random per-session key.
 * The ciphertext goes in the server session and the session key goes only in
 * a Secure, HttpOnly, SameSite=Strict cookie. Each request calls open() with
 * both halves to get the master key back in memory.
 */
class SessionKeyService
{
    private const CONTEXT = 'session:master-key';

    public function __construct(private readonly CryptoService $crypto = new CryptoService())
    {
    }

    public function seal(string $masterKey): SealedMasterKey
    {
        $cookieKey = $this->crypto->generateKey();
        $sealed = new SealedMasterKey(
            $this->crypto->encode($cookieKey),
            $this->crypto->encrypt($masterKey, $cookieKey, self::CONTEXT),
        );

        $this->crypto->wipe($cookieKey);

        return $sealed;
    }

    /**
     * @throws DecryptionException if either half is missing, wrong or tampered with.
     */
    public function open(string $cookieKey, string $ciphertext): string
    {
        $key = $this->crypto->decode($cookieKey);

        if ($key === null || strlen($key) !== CryptoService::KEY_BYTES) {
            throw new DecryptionException('The session key is malformed.');
        }

        try {
            return $this->crypto->decrypt($ciphertext, $key, self::CONTEXT);
        } finally {
            $this->crypto->wipe($key);
        }
    }
}
