<?php

namespace App\DTOs;

use App\Models\User;

/**
 * The signed-in user for the current request, built by the Authenticate
 * middleware and passed to controllers as request()->next('auth').
 */
class AuthContext
{
    public function __construct(
        public readonly User $user,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->user->isAdmin();
    }
}
