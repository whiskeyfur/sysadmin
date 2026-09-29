<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\PasswordService;

/**
 * Change your own password: any time, and forced once it's
 * PasswordService::MAX_AGE_DAYS old.
 */
class PasswordController extends Controller
{
    public function show()
    {
        $this->renderForm(notice: $this->request->flash('notice'));
    }

    public function update()
    {
        $auth = $this->authContext();

        $data = $this->request->validate([
            'password' => 'min:1',
            'new_password' => 'min:' . PasswordService::MIN_LENGTH,
            'new_password_confirmation' => 'matchesvalueof:new_password',
        ]);

        if ($data === false) {
            $errors = $this->request->errors();
            $this->renderForm(error: is_array($errors) && $errors !== [] ? (string) reset($errors) : 'Check the form and try again.');

            return;
        }

        $result = (new AuthService())->changePassword($auth->user, $data['password'], $data['new_password'], $this->clientIp());
        LimitConcurrentLogins::release();

        switch ($result->status) {
            case LoginStatus::Success:
                // The change ended every session, including this one; start a fresh one.
                (new AuthSessionService())->start($auth->user);
                $this->response->withFlash('notice', 'Password changed. It expires in ' . PasswordService::MAX_AGE_DAYS . ' days.')->redirect('/');

                return;

            case LoginStatus::PasswordRejected:
                $this->renderForm(error: (string) $result->message);

                return;

            case LoginStatus::TooManyAttempts:
                $this->response->withHeader('Retry-After', (string) $result->retryAfter);
                $this->renderForm(error: 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).', status: 429);

                return;

            default:
                $this->renderForm(error: 'Your current password is wrong.');
        }
    }

    private function renderForm(?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $auth = $this->authContext();

        $this->response->view('password.change', [
            'auth' => $auth,
            'expired' => $auth->passwordExpired,
            'maxAgeDays' => PasswordService::MAX_AGE_DAYS,
            'minLength' => PasswordService::MIN_LENGTH,
            'error' => $error,
            'notice' => $notice,
        ], $status);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
