<?php

namespace App\Controllers;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\TotpService;

/**
 * Sign in (username + authenticator code), first-login setup and sign out.
 *
 * On a first sign-in the one-time password opens setup; the pending setup
 * (user and the new authenticator's secret) is kept in the session, so the
 * one-time password isn't sent again.
 */
class AuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'Invalid username or code.';

    private const INVALID_ONE_TIME_PASSWORD = 'Invalid username or one-time password.';

    private readonly AuthService $auth;

    private readonly AuthSessionService $sessions;

    private readonly TotpService $totp;

    public function __construct()
    {
        parent::__construct();

        $this->auth = new AuthService();
        $this->sessions = new AuthSessionService();
        $this->totp = new TotpService();
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
        $code = (string) $this->request->get('code', false);

        if ($this->request->validate(['username' => 'username|between:[3,32]']) === false) {
            LimitConcurrentLogins::release();
            $this->renderLogin(error: self::INVALID_CREDENTIALS);

            return;
        }

        if ($oneTimePassword !== '') {
            $result = $this->auth->startSetup($username, $oneTimePassword, $this->clientIp());
            LimitConcurrentLogins::release();

            if ($result->status === LoginStatus::NeedsSetup && $result->user !== null) {
                $this->sessions->beginSetup($result->user, $this->totp->generateSecret());
                $this->response->redirect('/setup');

                return;
            }

            $this->respondWithLogin($result, self::INVALID_ONE_TIME_PASSWORD);

            return;
        }

        $result = $this->auth->attempt($username, $code, $this->clientIp());
        LimitConcurrentLogins::release();

        if ($result->status === LoginStatus::Success && $result->user !== null) {
            $this->sessions->start($result->user);
            $this->response->redirect('/');

            return;
        }

        $this->respondWithLogin($result, self::INVALID_CREDENTIALS);
    }

    public function showSetup()
    {
        $setup = $this->sessions->pendingSetup();

        if ($setup === null) {
            $this->response->withFlash('notice', 'Setup expired. Sign in with your one-time password again.')->redirect('/login');

            return;
        }

        $this->renderSetup($setup['user']->username, $setup['secret']);
    }

    public function setup()
    {
        $setup = $this->sessions->pendingSetup();

        if ($setup === null) {
            $this->response->withFlash('notice', 'Setup expired. Sign in with your one-time password again.')->redirect('/login');

            return;
        }

        $result = $this->auth->completeSetup($setup['user'], $setup['version'], $setup['secret'], (string) $this->request->get('code', false), $this->clientIp());

        switch ($result->status) {
            case LoginStatus::Success:
                $this->sessions->endSetup();
                $this->sessions->start($result->user);
                $this->response->redirect('/');

                return;

            case LoginStatus::InvalidCode:
                $this->renderSetup($setup['user']->username, $setup['secret'], "That code doesn't match. Enter the code the app shows now.");

                return;

            case LoginStatus::TooManyAttempts:
                $this->response->withHeader('Retry-After', (string) $result->retryAfter);
                $this->renderSetup($setup['user']->username, $setup['secret'], 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).', 429);

                return;

            default:
                $this->sessions->endSetup();
                $this->response->withFlash('notice', 'This account was reset or set up in the meantime. Sign in again.')->redirect('/login');
        }
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
        $this->response->view('auth.login', ['error' => $error, 'notice' => $notice], $status);
    }

    private function renderSetup(string $username, string $secret, ?string $error = null, int $status = 200): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('auth.setup', [
            'username' => $username,
            'secret' => $secret,
            'qr' => $this->totp->qrCode($this->totp->provisioningUri($secret, $username)),
            'error' => $error,
        ], $status);
    }
}
