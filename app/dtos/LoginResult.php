<?php

namespace App\DTOs;

use App\Enums\LoginStatus;
use App\Models\User;

/**
 * Result of an AuthService call. On Success it carries the master key for
 * AuthSessionService::start(); call wipe() once it has been sealed. On
 * TooManyAttempts, $retryAfter is the wait in seconds.
 */
class LoginResult
{
    public function __construct(
        public readonly LoginStatus $status,
        public readonly ?User $user = null,
        public ?string $masterKey = null,
        public readonly int $retryAfter = 0,
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
