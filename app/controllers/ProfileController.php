<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Models\Passkey;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\LoginMethodService;
use App\Services\PasskeyService;
use App\Services\ProfileService;
use App\Services\TotpService;
use DomainException;

/**
 * Profile (/profile): your own ways to sign in. Setup after a one-time
 * password happens here, and so does setting up a method an admin made
 * required. Changes need a recent sign-in or re-confirmation (with any
 * method you have), except during setup, where the one-time password was
 * just checked.
 */
class ProfileController extends Controller
{
    private readonly AuthSessionService $sessions;

    private readonly ProfileService $profile;

    private readonly LoginMethodService $methods;

    public function __construct()
    {
        parent::__construct();

        $this->sessions = new AuthSessionService();
        $this->profile = new ProfileService();
        $this->methods = new LoginMethodService();
    }

    public function show()
    {
        $this->renderProfile(notice: $this->request->flash('notice'), error: $this->request->flash('error'));
    }

    /**
     * Re-confirm with a password or authenticator code.
     */
    public function confirm()
    {
        $result = (new AuthService())->confirm($this->currentUser(), [
            'password' => (string) $this->request->get('password', false),
            'code' => (string) $this->request->get('code', false),
        ], $this->clientIp());
        LimitConcurrentLogins::release();

        if ($result->status === LoginStatus::Success) {
            $this->sessions->markConfirmed();
            $this->response->redirect('/profile');

            return;
        }

        $this->renderProfile(error: $result->status === LoginStatus::TooManyAttempts ? 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).' : "That didn't match.", status: $result->status === LoginStatus::TooManyAttempts ? 429 : 422);
    }

    /**
     * JSON: options to re-confirm with one of your passkeys (also used before revealing a password).
     */
    public function confirmPasskeyOptions()
    {
        $site = $this->site();

        if ($site === null) {
            $this->response->json(['error' => 'Passkeys aren\'t available here.'], 400);

            return;
        }

        $this->response->json((new PasskeyService())->assertionOptions($site['rp_id'], $this->sessions->newChallenge('confirm'), $this->currentUser()->username));
    }

    public function confirmPasskey()
    {
        $site = $this->site();
        $challenge = $this->sessions->takeChallenge('confirm');
        $credential = $this->request->get('credential', false);

        if ($site === null || $challenge === null || !is_array($credential)) {
            $this->response->json(['error' => 'That expired. Try again.'], 400);

            return;
        }

        $result = (new AuthService())->confirm($this->currentUser(), ['passkey' => [$site, $challenge, $credential]], $this->clientIp());

        if ($result->status !== LoginStatus::Success) {
            $this->response->json(['error' => $result->status === LoginStatus::TooManyAttempts ? 'Too many attempts. Try again later.' : "That passkey didn't work."], $result->status === LoginStatus::TooManyAttempts ? 429 : 401);

            return;
        }

        $this->sessions->markConfirmed();
        $this->sessions->rememberPasskeyConfirmation();
        $this->response->json(['ok' => true]);
    }

    public function setPassword()
    {
        $this->change(fn () => $this->profile->setPassword($this->currentUser(), (string) $this->request->get('new_password', false), (string) $this->request->get('new_password_again', false)), 'Password saved.');
        LimitConcurrentLogins::release();
    }

    public function removePassword()
    {
        $this->change(fn () => $this->profile->removePassword($this->currentUser()), 'Password removed.');
    }

    public function startAuthenticator()
    {
        $this->change(function () {
            $this->profile->requireEnabled(LoginMethodService::AUTHENTICATOR);
            $this->sessions->pendingTotpSecret((new TotpService())->generateSecret());
        }, null, '/profile#authenticator');
    }

    public function enrolAuthenticator()
    {
        $secret = $this->sessions->pendingTotpSecret();

        $this->change(function () use ($secret) {
            $this->profile->requireEnabled(LoginMethodService::AUTHENTICATOR);

            if ($secret === null) {
                throw new DomainException('Start again: the new authenticator\'s key expired.');
            }

            (new AuthService())->enrolAuthenticator($this->currentUser(), $secret, (string) $this->request->get('code', false));
            $this->sessions->forgetTotpSecret();
        }, 'Authenticator app set up.', '/profile#authenticator');
    }

    public function removeAuthenticator()
    {
        $this->change(fn () => $this->profile->removeAuthenticator($this->currentUser()), 'Authenticator removed.');
    }

    /**
     * JSON: options for navigator.credentials.create().
     */
    public function passkeyOptions()
    {
        $site = $this->site();

        if ($site === null || !$this->methods->isEnabled(LoginMethodService::PASSKEY) || !$this->confirmed()) {
            $this->response->json(['error' => $site === null ? 'Passkeys need https (or localhost).' : 'Confirm it\'s you first (reload the page).'], 400);

            return;
        }

        $this->response->json((new PasskeyService())->registrationOptions($this->currentUser(), $site['rp_id'], $this->sessions->newChallenge('register')));
    }

    /**
     * JSON {credential, name}: store the new passkey.
     */
    public function addPasskey()
    {
        $site = $this->site();
        $challenge = $this->sessions->takeChallenge('register');
        $credential = $this->request->get('credential', false);

        if ($site === null || $challenge === null || !is_array($credential) || !$this->confirmed()) {
            $this->response->json(['error' => 'That expired. Try again.'], 400);

            return;
        }

        try {
            $this->profile->addPasskey($this->currentUser(), $site, $challenge, (array) ($credential['response'] ?? []), (string) $this->request->get('name', false));
        } catch (DomainException $e) {
            $this->response->json(['error' => $e->getMessage()], 422);

            return;
        }

        $this->response->withFlash('notice', 'Passkey added.');
        $this->response->json(['ok' => true]);
    }

    public function removePasskey($id)
    {
        $this->change(fn () => $this->profile->removePasskey($this->currentUser(), (int) $id), 'Passkey removed.');
    }

    /**
     * Setup done: the one-time password goes, and the rest of the app opens.
     */
    public function finish()
    {
        try {
            (new AuthService())->completeSetup($this->currentUser());
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/profile');

            return;
        }

        $this->response->redirect('/');
    }

    /**
     * Run a change if the user confirmed recently; back to Profile with its outcome.
     */
    private function change(callable $action, ?string $done, string $back = '/profile'): void
    {
        if (!$this->confirmed()) {
            $this->response->withFlash('error', 'Confirm it\'s you first.')->redirect('/profile');

            return;
        }

        try {
            $action();
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect($back);

            return;
        }

        if ($done !== null) {
            $this->response->withFlash('notice', $done);
        }

        $this->response->redirect($back);
    }

    /**
     * Recently signed in or re-confirmed; during setup, the one-time password was just checked.
     */
    private function confirmed(): bool
    {
        return $this->currentUser()->must_change_password || $this->sessions->recentlyConfirmed();
    }

    private function renderProfile(?string $notice = null, ?string $error = null, int $status = 200): void
    {
        $user = $this->currentUser();
        $pending = $this->sessions->pendingTotpSecret();
        $totp = new TotpService();

        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('profile.show', [
            'auth' => $this->authContext(),
            'user' => $user,
            'levels' => $this->methods->levels(),
            'enrolled' => $this->methods->enrolled($user),
            'missing' => $this->methods->missingRequired($user),
            'usable' => $this->methods->usable($user),
            'setupDone' => $this->methods->setUpEnough($user),
            'confirmed' => $this->confirmed(),
            'passkeys' => Passkey::query()->where('user_id', $user->id)->orderBy('created_at')->get()->all(),
            'passkeysHere' => $this->site() !== null,
            'pendingSecret' => $pending,
            'qr' => $pending === null ? null : $totp->qrCode($totp->provisioningUri($pending, $user->username)),
            'notice' => $notice,
            'error' => $error,
        ], $status);
    }

    private function currentUser(): \App\Models\User
    {
        return $this->authContext()->user;
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
