<?php

namespace App\Middleware;

use App\Services\AuthSessionService;
use Leaf\Middleware;

/**
 * Requires a signed-in user and passes their AuthContext to the controller
 * as request()->next('auth').
 */
class Authenticate extends Middleware
{
    public function call()
    {
        $context = (new AuthSessionService())->current();

        if ($context === null) {
            response()->redirect('/login');

            return;
        }

        response()->next(['auth' => $context]);
    }
}
