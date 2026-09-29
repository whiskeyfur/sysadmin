<?php

namespace App\DTOs;

use App\Enums\LoginStatus;
use App\Models\User;

/**
 * Result of an AuthService call.
 */
class LoginResult
{
    public function __construct(
        public readonly LoginStatus $status,
        public readonly ?User $user = null,
        public readonly int $retryAfter = 0,
        public readonly ?string $message = null,
    ) {
    }
}
