<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\SettingsService;
use DomainException;

/**
 * App-wide settings (admins).
 */
class SettingsController extends Controller
{
    public function show()
    {
        $this->renderPage(notice: $this->request->flash('notice'));
    }

    public function update()
    {
        try {
            (new SettingsService())->update($this->authContext()->user, [
                SettingsService::SSL_WARNING_DAYS => $this->request->get(SettingsService::SSL_WARNING_DAYS, false),
                SettingsService::ACCOUNT_WARNING_DAYS => $this->request->get(SettingsService::ACCOUNT_WARNING_DAYS, false),
            ]);
        } catch (DomainException $e) {
            $this->renderPage(error: $e->getMessage(), status: 422);

            return;
        }

        $this->response->withFlash('notice', 'Settings saved. They apply from the next check.')->redirect('/admin/settings');
    }

    private function renderPage(?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $this->response->view('admin.settings', [
            'auth' => $this->authContext(),
            'settings' => new SettingsService(),
            'error' => $error,
            'notice' => $notice,
        ], $status);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
