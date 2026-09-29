<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\SettingsService;
use DomainException;

/**
 * Settings of each monitoring area (admins): /admin/settings/{ssl,ssh,mariadb},
 * reached from that area's menu.
 */
class SettingsController extends Controller
{
    private const TITLES = ['ssl' => 'SSL settings', 'ssh' => 'SSH settings', 'mariadb' => 'MariaDB settings'];

    /**
     * The old single settings page.
     */
    public function index()
    {
        $this->response->redirect('/admin/settings/ssh');
    }

    public function show($section)
    {
        if ($this->known($section)) {
            $this->renderPage($section, notice: $this->request->flash('notice'));
        }
    }

    public function update($section)
    {
        if (!$this->known($section)) {
            return;
        }

        $input = [];

        foreach (SettingsService::SECTIONS[$section] as $key) {
            $input[$key] = $this->request->get($key, false);
        }

        try {
            (new SettingsService())->update($this->authContext()->user, $input);
        } catch (DomainException $e) {
            $this->renderPage($section, error: $e->getMessage(), status: 422);

            return;
        }

        $this->response->withFlash('notice', 'Settings saved. They apply from the next check.')->redirect("/admin/settings/$section");
    }

    private function known(mixed $section): bool
    {
        if (is_string($section) && isset(self::TITLES[$section])) {
            return true;
        }

        $this->response->redirect('/admin/settings/ssh');

        return false;
    }

    private function renderPage(string $section, ?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $this->response->view('admin.settings', [
            'auth' => $this->authContext(),
            'section' => $section,
            'title' => self::TITLES[$section],
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
