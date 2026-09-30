<?php

namespace App\Controllers;

use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\LoginMethodService;

/**
 * For controllers whose changes need a fresh check with one of the signed-in user's methods (the
 * partials.confirm-fields form fields): a password, a new authenticator code, or a passkey check made
 * just before (a one-use flag in the session).
 */
trait ConfirmsIdentity
{
    /**
     * Null if the check passed, else why not.
     */
    protected function confirmIdentity(): ?string
    {
        if ($this->request->get('passkey_confirmed') && (new AuthSessionService())->takePasskeyConfirmation()) {
            return null;
        }

        $result = (new AuthService())->confirm($this->request->next('auth')->user, [
            'password' => (string) $this->request->get('password', false),
            'code' => (string) $this->request->get('code', false),
        ], $this->clientIp());
        LimitConcurrentLogins::release();

        return match ($result->status) {
            LoginStatus::Success => null,
            LoginStatus::TooManyAttempts => 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).',
            default => "Confirm it's you with your password, a new authenticator code or a passkey; nothing was changed.",
        };
    }

    /**
     * What partials.confirm-fields needs.
     *
     * @return array<string, mixed>
     */
    protected function confirmFields(): array
    {
        return [
            'usable' => (new LoginMethodService())->usable($this->request->next('auth')->user),
            'passkeysHere' => $this->site() !== null,
            'passkeyReady' => (new AuthSessionService())->hasPasskeyConfirmation(),
        ];
    }
}
