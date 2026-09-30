<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Services\LocalApacheService;
use App\Services\RemoteApacheService;
use App\Services\VhostEditorService;
use DomainException;

/**
 * One <VirtualHost> definition (/admin/vhost?id=...): this machine's or a monitored server's. Admins
 * on this machine only (RequireLocalAdmin), like the other Apache changes; saving and reloading need a
 * fresh sign-in check.
 */
class VhostEditorController extends Controller
{
    use ConfirmsIdentity;

    private const FIELDS = ['address', 'server_name', 'aliases', 'document_root', 'error_log', 'access_log', 'ssl_engine', 'ssl_certificate', 'ssl_key', 'ssl_chain', 'https_redirect'];

    private readonly VhostEditorService $editor;

    public function __construct()
    {
        parent::__construct();

        $this->editor = new VhostEditorService();
    }

    public function edit()
    {
        try {
            $loaded = $this->editor->load((string) $this->request->get('id', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect($this->request->get('back') === 'vhosts' ? '/vhosts' : '/admin/apache/local');

            return;
        }

        $this->renderEditor($loaded, notice: $this->request->flash('notice'), error: $this->request->flash('error'), output: $this->request->flash('output'));
    }

    /**
     * mode=fields or block: save (after a fresh check); mode=test: simulate a URL with the change (this machine only).
     */
    public function save()
    {
        try {
            $loaded = $this->editor->load((string) $this->request->get('id', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local');

            return;
        }

        $input = [];

        foreach (self::FIELDS as $field) {
            $input[$field] = trim((string) $this->request->get($field, false));
        }

        $blockText = (string) $this->request->get('block_text', false);
        $mode = (string) $this->request->get('mode');
        $edited = ['fields' => $input, 'blockText' => $blockText] + $loaded;

        try {
            $text = $mode === 'block' || ($mode === 'test' && $this->request->get('test_with') === 'block')
                ? $this->editor->withBlock($loaded, $blockText)
                : $this->editor->withFields($loaded, $input);
        } catch (DomainException $e) {
            $this->renderEditor($edited, error: $e->getMessage(), status: 422);

            return;
        }

        if ($mode === 'test') {
            $url = trim((string) $this->request->get('test_url', false));
            $trace = $loaded['server'] === null && $url !== '' ? $this->editor->simulator($loaded['file'], $text)->simulate($url) : null;
            $this->renderEditor($edited, trace: $trace, testUrl: $url);

            return;
        }

        $refused = $this->confirmIdentity();

        if ($refused !== null) {
            $this->renderEditor($edited, error: $refused, status: 422);

            return;
        }

        try {
            $result = $this->editor->save($this->authContext()->user, $loaded, (string) $this->request->get('hash', false), $text);
        } catch (DomainException $e) {
            $this->renderEditor($edited, error: $e->getMessage(), status: 422);

            return;
        }

        $this->response->withFlash('notice', 'Saved; the configuration test passed. Reload Apache to apply it.')->withFlash('output', (string) ($result['output'] ?? ''))
            ->redirect('/admin/vhost?id=' . urlencode($loaded['id']));
    }

    /**
     * Test the configuration, reload or restart Apache where this virtual host is.
     */
    public function service()
    {
        $id = (string) $this->request->get('id', false);
        $action = (string) $this->request->get('action');
        $back = '/admin/vhost?id=' . urlencode($id);

        try {
            if (!in_array($action, ['test', 'reload', 'restart'], true)) {
                throw new DomainException('Unknown action.');
            }

            if ($action !== 'test' && ($refused = $this->confirmIdentity()) !== null) {
                throw new DomainException($refused);
            }

            $server = str_starts_with($id, 'vhost:') ? \App\Models\ApacheVhost::query()->find((int) substr($id, 6))?->server : null;
            $result = $server instanceof Server
                ? (new RemoteApacheService())->service($this->authContext()->user, $server, $action)
                : (new LocalApacheService())->service($this->authContext()->user, $action);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect($back);

            return;
        }

        $this->response->withFlash('notice', match ($action) {
            'test' => ($result['ok'] ?? false) ? 'The configuration test passed.' : 'The configuration test failed.', 'reload' => 'Apache reloaded.', default => 'Apache restarted.'
        })
            ->withFlash('output', (string) ($result['output'] ?? ''))->redirect($back);
    }

    /**
     * @param array<string, mixed> $loaded
     * @param array<string, mixed>|null $trace
     */
    private function renderEditor(array $loaded, ?string $notice = null, ?string $error = null, ?string $output = null, ?array $trace = null, string $testUrl = '', int $status = 200): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.vhost-edit', [
            'auth' => $this->authContext(), 'loaded' => $loaded, 'notice' => $notice, 'error' => $error, 'output' => $output, 'trace' => $trace, 'testUrl' => $testUrl,
        ] + $this->confirmFields(), $status);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
