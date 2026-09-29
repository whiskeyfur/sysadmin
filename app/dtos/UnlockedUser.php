<?php

namespace App\DTOs;

/**
 * The result of unlocking a user's user-data field. Call wipe() as soon
 * as the master key is no longer needed (CLAUDE.md rule 8).
 */
class UnlockedUser
{
    public function __construct(
        public ?string $masterKey,
        public readonly bool $mustChangePassword,
    ) {
    }

    public function wipe(): void
    {
        if ($this->masterKey !== null) {
            sodium_memzero($this->masterKey);
        }

        $this->masterKey = null;
    }
}
