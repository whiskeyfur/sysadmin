<?php

namespace App\Services;

use App\Exceptions\DecryptionException;
use App\Exceptions\InvalidMasterKeyException;
use App\Exceptions\VaultStateException;
use App\Models\Vault;

/**
 * Owns the data key (CLAUDE.md rule 6). The data key encrypts app data and
 * never changes on master key rotation; only its wrapping is replaced.
 */
class VaultService
{
    private const CONTEXT = 'vault:data-key';

    public function __construct(private readonly CryptoService $crypto = new CryptoService())
    {
    }

    public function isInitialized(): bool
    {
        return Vault::query()->exists();
    }

    /**
     * Create the data key and the first master key. Returns the master key,
     * which the caller must wrap for the first user and offer as a key file.
     *
     * @throws VaultStateException if the vault already exists.
     */
    public function initialize(): string
    {
        if ($this->isInitialized()) {
            throw new VaultStateException('The vault is already initialized.');
        }

        $masterKey = $this->crypto->generateKey();
        $dataKey = $this->crypto->generateKey();

        Vault::query()->create([
            'wrapped_data_key' => $this->crypto->encrypt($dataKey, $masterKey, self::CONTEXT),
        ]);

        $this->crypto->wipe($dataKey);

        return $masterKey;
    }

    /**
     * @throws InvalidMasterKeyException if the master key does not unlock the vault.
     */
    public function unwrapDataKey(string $masterKey): string
    {
        try {
            return $this->crypto->decrypt($this->vault()->wrapped_data_key, $masterKey, self::CONTEXT);
        } catch (DecryptionException $e) {
            throw new InvalidMasterKeyException('The master key does not unlock the vault.', 0, $e);
        }
    }

    /**
     * @throws InvalidMasterKeyException
     */
    public function verifyMasterKey(string $masterKey): void
    {
        $dataKey = $this->unwrapDataKey($masterKey);
        $this->crypto->wipe($dataKey);
    }

    /**
     * Replace the master key: re-wrap the data key under a new random master
     * key and return it. Every user's stored master key becomes stale.
     *
     * @throws InvalidMasterKeyException
     */
    public function rotateMasterKey(string $currentMasterKey): string
    {
        $dataKey = $this->unwrapDataKey($currentMasterKey);
        $newMasterKey = $this->crypto->generateKey();

        $vault = $this->vault();
        $vault->wrapped_data_key = $this->crypto->encrypt($dataKey, $newMasterKey, self::CONTEXT);
        $vault->save();

        $this->crypto->wipe($dataKey);

        return $newMasterKey;
    }

    /**
     * @throws VaultStateException if the vault has not been initialized.
     */
    private function vault(): Vault
    {
        $vault = Vault::query()->first();

        if ($vault === null) {
            throw new VaultStateException('The vault has not been initialized.');
        }

        return $vault;
    }
}
