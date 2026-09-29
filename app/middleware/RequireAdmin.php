<?php

namespace App\Middleware;

use App\Services\AuthSessionService;
use Leaf\Middleware;

/**
 * Like Authenticate, but also requires the admin role.
 */
class RequireAdmin extends Middleware
{
    public function call()
    {
        $context = (new AuthSessionService())->current();

        if ($context === null) {
            response()->redirect('/login');

            return;
        }

        if (!$context->isAdmin()) {
            response()->redirect('/');

            return;
        }

        response()->next(['auth' => $context]);
    }
}
