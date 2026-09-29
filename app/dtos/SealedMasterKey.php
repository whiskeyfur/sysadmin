<?php

namespace App\DTOs;

/**
 * A master key encrypted for one login session (CLAUDE.md rule 7).
 * $cookieKey goes only to the client cookie; $ciphertext goes only
 * into the server session. Neither can recover the key on its own.
 */
class SealedMasterKey
{
    public function __construct(
        public readonly string $cookieKey,
        public readonly string $ciphertext,
    ) {
    }
}
