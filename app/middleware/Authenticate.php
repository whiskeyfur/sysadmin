<?php

namespace App\Middleware;

use App\Utils\BasePath;
use App\DTOs\AuthContext;
use App\Services\AuthSessionService;
use App\Services\LoginMethodService;
use App\Services\SettingsService;
use Leaf\Middleware;

/**
 * Requires a signed-in user and passes their AuthContext to the controller
 * as request()->next('auth').
 */
class Authenticate extends Middleware
{
    public function call()
    {
        $sessions = new AuthSessionService();
        $context = $sessions->current();

        if ($context === null) {
            if ($sessions->timedOut) {
                $minutes = (new SettingsService())->integer(SettingsService::SESSION_TIMEOUT_MINUTES);
                response()->withFlash('notice', "You were signed out after $minutes minute" . ($minutes === 1 ? '' : 's') . ' without activity.');
            }

            response()->redirect('/login');

            return;
        }

        // Still in setup, or missing a method an admin made required: Profile (and signing out) only.
        $path = BasePath::path();

        if (!str_starts_with($path, '/profile') && ($context->user->must_change_password || (new LoginMethodService())->missingRequired($context->user) !== [])) {
            response()->redirect('/profile');

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
