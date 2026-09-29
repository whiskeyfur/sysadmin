<?php

namespace App\Controllers;

use App\Enums\LoginStatus;
use App\Services\AuthService;
use App\Services\TotpService;

/**
 * Self-registration. Needs the key file (shared outside the website) and a
 * working authenticator; the account stays pending until an admin approves it.
 */
class RegisterController extends Controller
{
    private readonly AuthService $auth;

    private readonly TotpService $totp;

    public function __construct()
    {
        parent::__construct();

        $this->auth = new AuthService();
        $this->totp = new TotpService();
    }

    public function show()
    {
        $this->renderForm($this->totp->generateSecret());
    }

    public function store()
    {
        $secret = (string) $this->request->get('totp_secret', false);
        $username = (string) $this->request->get('username', false);

        if (!$this->totp->isValidSecret($secret)) {
            $this->renderForm($this->totp->generateSecret(), $username, 'The form expired. Scan the new code and try again.');

            return;
        }

        $data = $this->request->validate([
            'username' => 'username|between:[3,32]',
            'password' => 'min:12',
            'password_confirmation' => 'matchesvalueof:password',
            'code' => 'number|between:[6,6]',
        ]);
        // Not a validator rule: Leaf's rules are single-line patterns and the key file is multi-line JSON.
        $keyFile = trim((string) $this->request->get('key_file', false));

        if ($data !== false && $keyFile === '') {
            $this->renderForm($secret, $username, 'Choose the key file.');

            return;
        }

        if ($data === false) {
            $errors = $this->request->errors();
            $this->renderForm($secret, $username, is_array($errors) && $errors !== [] ? (string) reset($errors) : 'Check the form and try again.');

            return;
        }

        $result = $this->auth->register($data['username'], $data['password'], $keyFile, $secret, $data['code']);

        match ($result->status) {
            LoginStatus::Pending => $this->response
                ->withFlash('notice', 'Registration received. An admin must approve your account before you can sign in.')
                ->redirect('/login'),
            LoginStatus::InvalidCode => $this->renderForm($secret, $username, 'That code does not match the authenticator. Try the current code.'),
            LoginStatus::UsernameTaken => $this->renderForm($secret, $username, 'That username is taken.'),
            LoginStatus::InvalidKeyFile => $this->renderForm($secret, $username, 'That key file is not valid or is out of date.'),
            default => $this->renderForm($secret, $username, 'Registration failed. Try again.'),
        };
    }

    private function renderForm(string $secret, string $username = '', ?string $error = null): void
    {
        $label = parse_url((string) _env('APP_URL', ''), PHP_URL_HOST) ?: TotpService::ISSUER;

        $this->response->view('auth.register', [
            'username' => $username,
            'secret' => $secret,
            'qr' => $this->totp->qrCode($this->totp->provisioningUri($secret, $username !== '' ? $username : $label)),
            'error' => $error,
        ]);
    }
}
