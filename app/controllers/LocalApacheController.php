<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Enums\LoginStatus;
use App\Middleware\LimitConcurrentLogins;
use App\Services\AuthService;
use App\Services\AuthSessionService;
use App\Services\LocalApacheService;
use App\Services\LoginMethodService;
use DomainException;

/**
 * This machine's Apache (/admin/apache/local, Apache › This server):
 * admins on the machine itself (RequireLocalAdmin). Every change needs a
 * fresh check with one of your sign-in methods, like revealing a password.
 */
class LocalApacheController extends Controller
{
    private readonly LocalApacheService $apache;

    public function __construct()
    {
        parent::__construct();

        $this->apache = new LocalApacheService();
    }

    public function index()
    {
        $status = $this->apache->status();
        $overview = null;
        $log = [];
        $error = $this->request->flash('error');

        if ($status['ready']) {
            try {
                $overview = $this->apache->overview();
                $log = $this->apache->errorLog(60);
            } catch (DomainException $e) {
                $error = $e->getMessage();
            }
        }

        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.local', $this->common() + [
            'status' => $status,
            'overview' => $overview,
            'errorLog' => $log,
            'changes' => $this->apache->recentChanges(),
            'notice' => $this->request->flash('notice'),
            'output' => $this->request->flash('output'),
            'error' => $error,
        ]);
    }

    public function edit()
    {
        $kind = (string) $this->request->get('kind');
        $name = (string) $this->request->get('name');

        try {
            $content = $this->apache->read($kind, $name);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local');

            return;
        }

        $this->renderEdit($kind, $name, $content);
    }

    public function save()
    {
        $kind = (string) $this->request->get('kind');
        $name = (string) $this->request->get('name');
        $content = (string) $this->request->get('content', false);
        $refused = $this->confirm();

        if ($refused !== null) {
            $this->renderEdit($kind, $name, $content, $refused, 422);

            return;
        }

        try {
            $result = $this->apache->write($this->authContext()->user, $kind, $name, $content);
        } catch (DomainException $e) {
            $this->renderEdit($kind, $name, $content, $e->getMessage(), 422);

            return;
        }

        $this->response->withFlash('notice', 'Saved ' . ($result['path'] ?? '') . '; Apache\'s configuration test passed. Reload Apache to apply it.')->redirect('/admin/apache/local');
    }

    public function create()
    {
        $this->renderNew();
    }

    public function store()
    {
        $input = [];

        foreach (['name', 'server_name', 'aliases', 'port', 'document_root', 'error_log', 'access_log', 'ssl_certificate', 'ssl_key', 'enable'] as $field) {
            $input[$field] = trim((string) $this->request->get($field, false));
        }

        $refused = $this->confirm();

        if ($refused !== null) {
            $this->renderNew($input, $refused);

            return;
        }

        try {
            $this->apache->createSite($this->authContext()->user, $input);
        } catch (DomainException $e) {
            $this->renderNew($input, $e->getMessage());

            return;
        }

        $this->response->withFlash('notice', "Created site {$input['name']}" . ($input['enable'] ? ' and enabled it' : '') . '. Reload Apache to apply it.')->redirect('/admin/apache/local');
    }

    /**
     * One form on the page, one button per change: "enable:site:NAME", "disable:mod:NAME", "reload", "restart", "test".
     */
    public function change()
    {
        $parts = explode(':', (string) $this->request->get('change', false), 3);
        $action = $parts[0];

        if ($action !== 'test') {
            $refused = $this->confirm();

            if ($refused !== null) {
                $this->response->withFlash('error', $refused)->redirect('/admin/apache/local');

                return;
            }
        }

        try {
            $result = in_array($action, ['enable', 'disable'], true)
                ? $this->apache->toggle($this->authContext()->user, $action, $parts[1] ?? '', $parts[2] ?? '')
                : $this->apache->service($this->authContext()->user, $action);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local');

            return;
        }

        $done = match ($action) {
            'enable', 'disable' => ucfirst($action) . 'd ' . ($parts[1] ?? '') . ' ' . ($parts[2] ?? '') . '. Reload Apache to apply it.',
            'test' => ($result['ok'] ?? false) ? 'The configuration test passed.' : 'The configuration test failed.',
            default => 'Apache ' . ($action === 'reload' ? 'reloaded' : 'restarted') . '.',
        };
        $this->response->withFlash('notice', $done)->withFlash('output', (string) ($result['output'] ?? ''))->redirect('/admin/apache/local');
    }

    /**
     * A fresh check with one of the admin's sign-in methods: null if it passed, else why not.
     */
    private function confirm(): ?string
    {
        if ($this->request->get('passkey_confirmed') && (new AuthSessionService())->takePasskeyConfirmation()) {
            return null;
        }

        $result = (new AuthService())->confirm($this->authContext()->user, [
            'password' => (string) $this->request->get('password', false),
            'code' => (string) $this->request->get('code', false),
        ], $this->clientIp());
        LimitConcurrentLogins::release();

        return match ($result->status) {
            LoginStatus::Success => null,
            LoginStatus::TooManyAttempts => 'Too many attempts. Try again in ' . $this->retryMinutes($result->retryAfter) . ' minute(s).',
            default => "Confirm it's you with your password, a new authenticator code or a passkey; nothing was changed.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function common(): array
    {
        return [
            'auth' => $this->authContext(),
            'usable' => (new LoginMethodService())->usable($this->authContext()->user),
            'passkeysHere' => $this->site() !== null,
            'passkeyReady' => (new AuthSessionService())->hasPasskeyConfirmation(),
        ];
    }

    private function renderEdit(string $kind, string $name, string $content, ?string $error = null, int $status = 200): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.local-edit', $this->common() + ['kind' => $kind, 'name' => $name, 'content' => $content, 'error' => $error], $status);
    }

    /**
     * @param array<string, string> $old
     */
    private function renderNew(array $old = [], ?string $error = null): void
    {
        $this->response->view('apache.local-new', $this->common() + ['old' => $old, 'error' => $error], $error === null ? 200 : 422);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
