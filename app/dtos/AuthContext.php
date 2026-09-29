<?php

namespace App\DTOs;

use App\Models\User;
use App\Services\UserKeyService;

/**
 * The signed-in user and their master key for the current request, built
 * by the Authenticate middleware from the session and cookie halves.
 */
class AuthContext
{
    public function __construct(
        public readonly User $user,
        public ?string $masterKey,
        public readonly string $role,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === UserKeyService::ROLE_ADMIN;
    }

    public function wipe(): void
    {
        if ($this->masterKey !== null) {
            sodium_memzero($this->masterKey);
        }

        $this->masterKey = null;
    }
}
