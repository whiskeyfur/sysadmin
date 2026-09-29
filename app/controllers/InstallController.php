<?php

namespace App\Controllers;

use App\Services\DatabaseSetupService;
use App\Utils\DatabaseConfig;
use App\Utils\InstallCode;

/**
 * The install wizard (/install/database): the first page of a new install,
 * until a database is set up (RequireDatabase sends every request here).
 * Protected by the install code, which only someone on the server can read.
 */
class InstallController extends Controller
{
    private const FIELDS = ['driver', 'host', 'port', 'database', 'username', 'app_username', 'sqlite_file'];

    public function show()
    {
        InstallCode::current();
        $this->renderForm();
    }

    public function store()
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $input[$field] = trim((string) $this->request->get($field, false));
        }

        if (!InstallCode::matches((string) $this->request->get('install_code', false))) {
            $this->renderForm("That isn't the install code. Read it on the server: storage/framework/install-code, or run `php leaf app:install-code`.", $input, 403);

            return;
        }

        $input['password'] = (string) $this->request->get('password', false);

        $settings = $input['driver'] === 'sqlite' ? ['database' => $input['sqlite_file']] + $input : $input;
        $legacy = DatabaseSetupService::legacySqlite();
        $copyFrom = $legacy !== null && $this->request->get('copy_existing') && realpath($legacy) !== realpath($settings['driver'] === 'sqlite' ? $settings['database'] : '') ? $legacy : null;

        try {
            $messages = (new DatabaseSetupService())->install($settings, $copyFrom, (bool) $this->request->get('replace'));
        } catch (\DomainException $e) {
            $this->renderForm($e->getMessage(), $input, 422);

            return;
        } catch (\Throwable $e) {
            $this->renderForm('Setup failed: ' . $e->getMessage(), $input, 500);

            return;
        }

        InstallCode::forget();
        $messages[] = $copyFrom === null
            ? 'Next: sign in as admin with the one-time password "changeme" and connect your authenticator right away.'
            : 'Next: sign in with your existing account.';
        $this->response->withFlash('notice', implode(' ', $messages))->redirect('/login');
    }

    /**
     * @param array<string, string> $old
     */
    private function renderForm(?string $error = null, array $old = [], int $status = 200): void
    {
        $this->response->view('install.database', [
            'error' => $error,
            'old' => $old,
            'drivers' => array_map(fn ($driver) => ['label' => DatabaseSetupService::LABELS[$driver], 'available' => DatabaseSetupService::available($driver)], array_combine(array_keys(DatabaseSetupService::LABELS), array_keys(DatabaseSetupService::LABELS))),
            'ports' => DatabaseSetupService::DEFAULT_PORTS,
            'legacy' => DatabaseSetupService::legacySqlite(),
            'defaultSqlite' => dirname(__DIR__, 2) . '/storage/app/db/app.sqlite',
            'configPath' => DatabaseConfig::path(),
            'codePath' => InstallCode::path(),
        ], $status);
    }
}
