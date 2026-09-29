<?php

namespace App\Controllers;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\PasswordService;
use App\Services\TotpService;

/**
 * Sign in, first-login setup and sign out. Setup re-asks for the current
 * password rather than keeping it between requests.
 */
class AuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'Invalid username, password or authenticator code.';

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

        $data = $this->request->validate([
            'username' => 'username|between:[3,32]',
            'password' => 'min:1',
            'code' => 'optional|number|between:[6,6]',
        ]);

        if ($data === false) {
            $this->renderLogin(error: self::INVALID_CREDENTIALS);

            return;
        }

        $result = $this->auth->attempt($data['username'], $data['password'], (string) ($data['code'] ?? ''), $this->clientIp());
        $this->respond($result, $data['username']);
    }

    public function setup()
    {
        $username = (string) $this->request->get('username', false);
        $secret = (string) $this->request->get('totp_secret', false);

        if (!$this->totp->isValidSecret($secret)) {
            $this->renderLogin(error: 'Setup expired. Sign in again.');

            return;
        }

        $data = $this->request->validate([
            'username' => 'username|between:[3,32]',
            'password' => 'min:1',
            'new_password' => 'min:' . PasswordService::MIN_LENGTH,
            'new_password_confirmation' => 'matchesvalueof:new_password',
            'code' => 'number|between:[6,6]',
        ]);

        if ($data === false) {
            $this->renderSetup($username, $secret, $this->firstError());

            return;
        }

        $result = $this->auth->completeSetup($data['username'], $data['password'], $data['new_password'], $secret, $data['code'], $this->clientIp());
        $this->respond($result, $data['username'], $secret);
    }

    public function logout()
    {
        $this->sessions->end();
        $this->response->redirect('/login');
    }

    private function respond(LoginResult $result, string $username, ?string $setupSecret = null): void
    {
        LimitConcurrentLogins::release();

        switch ($result->status) {
            case LoginStatus::Success:
                $this->sessions->start($result->user);
                $this->response->redirect('/');

                return;

            case LoginStatus::NeedsSetup:
                $this->renderSetup($username, $this->totp->generateSecret());

                return;

            case LoginStatus::InvalidCode:
                $this->renderSetup($username, (string) $setupSecret, 'That code does not match the new authenticator. Try the current code.');

                return;

            case LoginStatus::PasswordRejected:
                $this->renderSetup($username, (string) $setupSecret, (string) $result->message);

                return;

            case LoginStatus::TooManyAttempts:
                $this->response->withHeader('Retry-After', (string) $result->retryAfter);
                $this->renderLogin(
                    error: 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).',
                    status: 429,
                );

                return;

            default:
                $this->renderLogin(error: self::INVALID_CREDENTIALS);
        }
    }

    private function renderLogin(?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $this->response->view('auth.login', ['error' => $error, 'notice' => $notice], $status);
    }

    private function renderSetup(string $username, string $secret, ?string $error = null): void
    {
        $this->response->view('auth.setup', [
            'username' => $username,
            'secret' => $secret,
            'qr' => $this->totp->qrCode($this->totp->provisioningUri($secret, $username)),
            'error' => $error,
            'minLength' => PasswordService::MIN_LENGTH,
        ]);
    }

    private function firstError(): string
    {
        $errors = $this->request->errors();

        return is_array($errors) && $errors !== [] ? (string) reset($errors) : 'Check the form and try again.';
    }
}
