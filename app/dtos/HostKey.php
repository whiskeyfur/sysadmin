<?php

namespace App\DTOs;

use InvalidArgumentException;

/**
 * An SSH host key as "type base64-blob", e.g. "ssh-ed25519 AAAAC3...".
 * Two host keys are the same key when their blobs match; the type prefix
 * can differ for one RSA key (ssh-rsa, rsa-sha2-256, rsa-sha2-512).
 */
class HostKey
{
    public function __construct(
        public readonly string $type,
        public readonly string $blob,
    ) {
    }

    public static function fromString(string $key): self
    {
        $parts = preg_split('/\s+/', trim($key));

        if (!is_array($parts) || count($parts) < 2 || base64_decode($parts[1], true) === false) {
            throw new InvalidArgumentException('Not an SSH host key.');
        }

        return new self($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return "{$this->type} {$this->blob}";
    }

    /**
     * OpenSSH-style fingerprint, comparable with `ssh-keygen -lf <key>.pub`.
     */
    public function fingerprint(): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode($this->blob, true), true)), '=');
    }

    public function sameKeyAs(self $other): bool
    {
        return hash_equals($this->blob, $other->blob);
    }

    /**
     * Host key algorithms to offer so the server presents this same key.
     *
     * @return list<string>
     */
    public function algorithms(): array
    {
        return match (true) {
            in_array($this->type, ['ssh-rsa', 'rsa-sha2-256', 'rsa-sha2-512'], true) => ['rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'],
            default => [$this->type],
        };
    }
}
