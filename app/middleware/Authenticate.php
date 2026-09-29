<?php

namespace App\Middleware;

use App\DTOs\AuthContext;
use App\Services\AuthSessionService;
use Leaf\Middleware;

/**
 * Requires a signed-in user and passes their AuthContext to the controller
 * as request()->next('auth'). Users whose password has expired are sent to
 * the change-password page until they change it.
 */
class Authenticate extends Middleware
{
    protected bool $allowExpiredPassword = false;

    public function call()
    {
        $context = (new AuthSessionService())->current();

        if ($context === null) {
            response()->redirect('/login');

            return;
        }

        if ($context->passwordExpired && !$this->allowExpiredPassword) {
            response()->redirect('/password');

            return;
        }

        $this->authorize($context);

        response()->next(['auth' => $context]);
    }

    /**
     * Extra checks for subclasses; redirect (which exits) to refuse.
     */
    protected function authorize(AuthContext $context): void
    {
    }
}
