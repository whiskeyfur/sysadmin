<?php

namespace App\Middleware;

use App\DTOs\AuthContext;

/**
 * Like Authenticate, but also requires the admin role.
 */
class RequireAdmin extends Authenticate
{
    protected function authorize(AuthContext $context): void
    {
        if (!$context->isAdmin()) {
            response()->redirect('/');
        }
    }
}
