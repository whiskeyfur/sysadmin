<?php

namespace App\Controllers;

use App\DTOs\LoginResult;
use App\Enums\LoginStatus;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\TotpService;

/**
 * Sign in, first-login setup, stale key replacement and sign out. Every
 * step re-asks for the password rather than keeping it between requests.
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

        $result = $this->auth->attempt($data['username'], $data['password'], (string) ($data['code'] ?? ''));
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
            'new_password' => 'min:12',
            'new_password_confirmation' => 'matchesvalueof:new_password',
            'code' => 'number|between:[6,6]',
        ]);

        if ($data === false) {
            $this->renderSetup($username, $secret, $this->firstError());

            return;
        }

        if ($data['new_password'] === $data['password']) {
            $this->renderSetup($username, $secret, 'Choose a password different from the current one.');

            return;
        }

        $result = $this->auth->completeSetup($data['username'], $data['password'], $data['new_password'], $secret, $data['code']);
        $this->respond($result, $data['username'], $secret);
    }

    public function replaceKey()
    {
        $username = (string) $this->request->get('username', false);

        $data = $this->request->validate([
            'username' => 'username|between:[3,32]',
            'password' => 'min:1',
            'code' => 'number|between:[6,6]',
        ]);
        // Not a validator rule: Leaf's rules are single-line patterns and the key file is multi-line JSON.
        $keyFile = trim((string) $this->request->get('key_file', false));

        if ($data === false || $keyFile === '') {
            $this->renderReplaceKey($username, 'Enter your password, a code from your authenticator and the key file.');

            return;
        }

        $result = $this->auth->replaceKey($data['username'], $data['password'], $data['code'], $keyFile);
        $this->respond($result, $data['username']);
    }

    public function logout()
    {
        $this->sessions->end();
        $this->response->redirect('/login');
    }

    private function respond(LoginResult $result, string $username, ?string $setupSecret = null): void
    {
        switch ($result->status) {
            case LoginStatus::Success:
                $this->sessions->start($result->user, (string) $result->masterKey);
                $result->wipe();
                $this->response->redirect('/');

                return;

            case LoginStatus::NeedsSetup:
                $this->renderSetup($username, $this->totp->generateSecret());

                return;

            case LoginStatus::InvalidCode:
                $this->renderSetup($username, (string) $setupSecret, 'That code does not match the new authenticator. Try the current code.');

                return;

            case LoginStatus::StaleKey:
                $this->renderReplaceKey($username, 'The master key has changed. Upload the current key file to continue.');

                return;

            case LoginStatus::InvalidKeyFile:
                $this->renderReplaceKey($username, 'That key file is not valid or is out of date.');

                return;

            case LoginStatus::Pending:
                $this->renderLogin(notice: 'Your account is waiting for an admin to approve it.');

                return;

            default:
                $this->renderLogin(error: self::INVALID_CREDENTIALS);
        }
    }

    private function renderLogin(?string $error = null, ?string $notice = null): void
    {
        $this->response->view('auth.login', ['error' => $error, 'notice' => $notice]);
    }

    private function renderSetup(string $username, string $secret, ?string $error = null): void
    {
        $uri = $this->totp->provisioningUri($secret, $username);

        $this->response->view('auth.setup', [
            'username' => $username,
            'secret' => $secret,
            'qr' => $this->totp->qrCode($uri),
            'error' => $error,
        ]);
    }

    private function renderReplaceKey(string $username, ?string $error = null): void
    {
        $this->response->view('auth.replace-key', ['username' => $username, 'error' => $error]);
    }

    private function firstError(): string
    {
        $errors = $this->request->errors();

        return is_array($errors) && $errors !== [] ? (string) reset($errors) : 'Check the form and try again.';
    }
}
