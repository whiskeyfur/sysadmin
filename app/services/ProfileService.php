<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use DomainException;

/**
 * A user setting up and changing their own ways to sign in (the Profile
 * page), within what the admin turned on: a method that's off can't be set
 * up; a required one, or the last one the user can sign in with, can't be
 * removed. The controller makes sure the user proved it's them recently.
 */
class ProfileService
{
    public function __construct(
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly LoginMethodService $methods = new LoginMethodService(),
        private readonly PasskeyService $passkeys = new PasskeyService(),
    ) {
    }

    /**
     * @throws DomainException
     */
    public function setPassword(User $user, string $password, string $repeated): void
    {
        $this->requireEnabled(LoginMethodService::PASSWORD);

        if ($password !== $repeated) {
            throw new DomainException("The two passwords don't match.");
        }

        $error = $this->passwords->userPolicyError($user, $password);

        if ($error !== null) {
            throw new DomainException($error);
        }

        $this->passwords->setUserPassword($user, $password);
        $user->save();
    }

    /**
     * @throws DomainException
     */
    public function removePassword(User $user): void
    {
        $this->requireRemovable($user, LoginMethodService::PASSWORD);
        $user->login_password = null;
        $user->password_changed_at = null;
        $user->save();
    }

    /**
     * @throws DomainException
     */
    public function removeAuthenticator(User $user): void
    {
        $this->requireRemovable($user, LoginMethodService::AUTHENTICATOR);
        $user->totp_secret = null;
        $user->totp_last_step = null;
        $user->save();
    }

    /**
     * @param array{rp_id: string, origin: string} $site
     * @param array<string, mixed> $response
     *
     * @throws DomainException
     */
    public function addPasskey(User $user, array $site, string $challenge, array $response, string $name): Passkey
    {
        $this->requireEnabled(LoginMethodService::PASSKEY);

        return $this->passkeys->register($user, $site, $challenge, $response, $name);
    }

    /**
     * @throws DomainException
     */
    public function removePasskey(User $user, int $id): void
    {
        $passkey = Passkey::query()->where('user_id', $user->id)->find($id);

        if (!$passkey instanceof Passkey) {
            throw new DomainException('That passkey is gone already.');
        }

        if (Passkey::query()->where('user_id', $user->id)->count() === 1) {
            $this->requireRemovable($user, LoginMethodService::PASSKEY);
        }

        $passkey->delete();
    }

    public function requireEnabled(string $method): void
    {
        if (!$this->methods->isEnabled($method)) {
            throw new DomainException(LoginMethodService::LABELS[$method] . ' sign-in is turned off.');
        }
    }

    private function requireRemovable(User $user, string $method): void
    {
        if (!$this->methods->canRemove($user, $method)) {
            throw new DomainException(in_array($method, $this->methods->required(), true)
                ? LoginMethodService::LABELS[$method] . ' is required: replace it instead of removing it.'
                : "It's your last way to sign in: set up another first.");
        }
    }
}
