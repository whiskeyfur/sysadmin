<?php

namespace App\Controllers;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\LoginMethodService;
use App\Services\PasskeyService;

/**
 * Sign in (username and any one method the admin turned on: password,
 * authenticator code or passkey), first sign-in with a one-time password
 * (which leads to the Profile page to set up the required methods), and
 * sign out.
 */
class AuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'Invalid username, password or code.';

    private const INVALID_ONE_TIME_PASSWORD = 'Invalid username or one-time password.';

    private readonly AuthService $auth;

    private readonly AuthSessionService $sessions;

    public function __construct()
    {
        parent::__construct();

        $this->auth = new AuthService();
        $this->sessions = new AuthSessionService();
    }

    public function showLogin()
    {
        $this->auth->ensureDefaultAdmin();

        if ($this->sessions->current() !== null) {
            $this->response->redirect('/');

            return;
        }

        $this->renderLogin(notice: $this->request->flash('notice'));
    }

    public function login()
    {
        $this->auth->ensureDefaultAdmin();

        $username = (string) $this->request->get('username', false);
        $oneTimePassword = (string) $this->request->get('one_time_password', false);
        $password = (string) $this->request->get('password', false);
        $code = (string) $this->request->get('code', false);

        if ($this->request->validate(['username' => 'username|between:[3,32]']) === false || ($oneTimePassword === '' && $password === '' && $code === '')) {
            LimitConcurrentLogins::release();
            $this->renderLogin(error: self::INVALID_CREDENTIALS);

            return;
        }

        if ($oneTimePassword !== '') {
            $result = $this->auth->startSetup($username, $oneTimePassword, $this->clientIp());
            LimitConcurrentLogins::release();

            if ($result->status === LoginStatus::NeedsSetup && $result->user !== null) {
                // Signed in, but only to set up the ways to sign in (the Authenticate middleware keeps it to Profile).
                $this->sessions->start($result->user);
                $this->response->redirect('/profile');

                return;
            }

            $this->respondWithLogin($result, self::INVALID_ONE_TIME_PASSWORD);

            return;
        }

        $result = $this->auth->attempt($username, $password, $code, $this->clientIp());
        LimitConcurrentLogins::release();

        if ($result->status === LoginStatus::Success && $result->user !== null) {
            $this->sessions->start($result->user);
            $this->response->redirect('/');

            return;
        }

        $this->respondWithLogin($result, self::INVALID_CREDENTIALS);
    }

    /**
     * POST /login/passkey/options (JSON): options for navigator.credentials.get().
     */
    public function passkeyOptions()
    {
        $site = $this->site();

        if ($site === null || !(new LoginMethodService())->isEnabled(LoginMethodService::PASSKEY)) {
            $this->response->json(['error' => 'Passkeys aren\'t available here.'], 400);

            return;
        }

        $challenge = $this->sessions->newChallenge('login');
        $this->response->json((new PasskeyService())->assertionOptions($site['rp_id'], $challenge, trim((string) $this->request->get('username', false))));
    }

    /**
     * POST /login/passkey (JSON {credential}): sign in with the passkey.
     */
    public function passkeyLogin()
    {
        $site = $this->site();
        $challenge = $this->sessions->takeChallenge('login');
        $credential = $this->request->get('credential', false);

        if ($site === null || $challenge === null || !is_array($credential)) {
            $this->response->json(['error' => 'The sign-in expired. Try again.'], 400);

            return;
        }

        $result = $this->auth->attemptPasskey($site, $challenge, $credential, $this->clientIp());

        if ($result->status === LoginStatus::Success && $result->user !== null) {
            $this->sessions->start($result->user);
            $this->response->json(['redirect' => '/']);

            return;
        }

        if ($result->status === LoginStatus::TooManyAttempts) {
            $this->response->withHeader('Retry-After', (string) $result->retryAfter);
            $this->response->json(['error' => 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).'], 429);

            return;
        }

        $this->response->json(['error' => "That passkey didn't work here."], 401);
    }

    /**
     * The old setup page: setup is on Profile now.
     */
    public function showSetup()
    {
        $this->response->redirect('/profile');
    }

    public function logout()
    {
        $this->sessions->end();
        $this->response->redirect('/login');
    }

    private function respondWithLogin(LoginResult $result, string $invalid): void
    {
        if ($result->status === LoginStatus::TooManyAttempts) {
            $this->response->withHeader('Retry-After', (string) $result->retryAfter);
            $this->renderLogin(error: 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).', status: 429);

            return;
        }

        $this->renderLogin(error: $invalid);
    }

    private function renderLogin(?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $this->response->view('auth.login', [
            'error' => $error,
            'notice' => $notice,
            'methods' => (new LoginMethodService())->enabled(),
            'passkeysHere' => $this->site() !== null,
        ], $status);
    }
}
