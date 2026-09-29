<?php

namespace App\DTOs;

/**
 * The result of decrypting a user's user-data field. Call wipe() as soon
 * as the secrets are no longer needed (CLAUDE.md rule 8).
 */
class UnlockedUser
{
    public function __construct(
        public ?string $masterKey,
        public readonly bool $mustChangePassword,
        public ?string $totpSecret = null,
    ) {
    }

    public function hasAuthenticator(): bool
    {
        return $this->totpSecret !== null;
    }

    public function wipe(): void
    {
        if ($this->masterKey !== null) {
            sodium_memzero($this->masterKey);
        }

        if ($this->totpSecret !== null) {
            sodium_memzero($this->totpSecret);
        }

        $this->masterKey = null;
        $this->totpSecret = null;
    }
}
