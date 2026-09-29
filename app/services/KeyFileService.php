<?php

namespace App\Services;

use App\Exceptions\InvalidKeyFileException;

/**
 * Converts the master key to and from the key file users share outside the
 * website (CLAUDE.md rule 1). Works on strings only: the key file must never
 * be written to disk on the server, so callers pass the POSTed contents in
 * and stream the export straight into the response.
 */
class KeyFileService
{
    public const FORMAT = 'sys-master-key';

    public const VERSION = 1;

    public function __construct(private readonly CryptoService $crypto = new CryptoService())
    {
    }

    public function export(string $masterKey): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'key' => $this->crypto->encode($masterKey),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws InvalidKeyFileException
     */
    public function parse(string $contents): string
    {
        $data = json_decode($contents, true);

        if (
            !is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || ($data['version'] ?? null) !== self::VERSION
            || !is_string($data['key'] ?? null)
        ) {
            throw new InvalidKeyFileException('This is not a valid key file.');
        }

        $key = $this->crypto->decode($data['key']);

        if ($key === null || strlen($key) !== CryptoService::KEY_BYTES) {
            throw new InvalidKeyFileException('The key file does not contain a valid key.');
        }

        return $key;
    }

    public function filename(): string
    {
        return 'sys-master-key-' . gmdate('Y-m-d') . '.json';
    }
}
