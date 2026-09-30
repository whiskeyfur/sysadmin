<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Services\RewriteEditorService;
use App\Services\RewriteRules;
use DomainException;

/**
 * mod_rewrite rules of this machine's Apache (/admin/apache/local/rewrite) and the URL simulator
 * (/admin/apache/local/simulate): admins on this machine (RequireLocalAdmin). Saving needs a fresh
 * sign-in check; trying a change against a URL before saving doesn't.
 */
class RewriteController extends Controller
{
    use ConfirmsIdentity;

    private readonly RewriteEditorService $editor;

    public function __construct()
    {
        parent::__construct();

        $this->editor = new RewriteEditorService();
    }

    public function index()
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.rewrite-index', [
            'auth' => $this->authContext(),
            'scopes' => $this->editor->scopes(),
            'notes' => $this->editor->tree()->notes,
            'error' => $this->request->flash('error'),
        ]);
    }

    public function scope()
    {
        $dir = trim((string) $this->request->get('dir', false));

        try {
            $scope = $this->editor->scope($dir !== '' ? 'htaccess:' . rtrim($dir, '/') : (string) $this->request->get('id', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local/rewrite');

            return;
        }

        $this->renderScope($scope, notice: $this->request->flash('notice'), error: $this->request->flash('error'));
    }

    /**
     * A rule's form: ?id=SCOPE&index=N (or no index for a new rule).
     */
    public function rule()
    {
        try {
            $scope = $this->editor->scope((string) $this->request->get('id', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local/rewrite');

            return;
        }

        $index = $this->ruleIndex();
        $rule = $index === null ? ['conds' => [], 'pattern' => '', 'substitution' => '', 'flags' => [['L', null]]] : ($scope['rules']->rules[$index] ?? null);

        if ($rule === null) {
            $this->response->withFlash('error', 'That rule is gone.')->redirect('/admin/apache/local/rewrite/scope?id=' . urlencode($scope['id']));

            return;
        }

        $this->renderRule($scope, $index, $rule);
    }

    /**
     * Save the rule (action=save, needs a fresh check), or try it on a URL first (action=test).
     */
    public function saveRule()
    {
        try {
            $scope = $this->editor->scope((string) $this->request->get('id', false));
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect('/admin/apache/local/rewrite');

            return;
        }

        $index = $this->ruleIndex();
        $rule = $this->ruleFromForm();

        try {
            $text = $this->editor->withRule($scope, $index, $rule);
        } catch (DomainException $e) {
            $this->renderRule($scope, $index, $rule, error: $e->getMessage());

            return;
        }

        if ($this->request->get('action') === 'test') {
            $url = trim((string) $this->request->get('test_url', false));
            $trace = $url === '' ? null : $this->editor->simulator($scope['file'], $text)->simulate($url, $this->simulationRequest());
            $this->renderRule($scope, $index, $rule, trace: $trace, testUrl: $url);

            return;
        }

        $refused = $this->confirmIdentity();

        if ($refused !== null) {
            $this->renderRule($scope, $index, $rule, error: $refused);

            return;
        }

        try {
            $this->editor->save($this->authContext()->user, $scope, (string) $this->request->get('hash', false), $text);
        } catch (DomainException $e) {
            $this->renderRule($scope, $index, $rule, error: $e->getMessage());

            return;
        }

        $this->response->withFlash('notice', ($index === null ? 'Rule added' : 'Rule saved') . ($scope['kind'] === 'htaccess' ? '; it applies right away.' : '; Apache\'s configuration test passed. Reload Apache to apply it.'))
            ->redirect('/admin/apache/local/rewrite/scope?id=' . urlencode($scope['id']));
    }

    /**
     * Delete, move, or change RewriteEngine/RewriteBase: op = delete:N, up:N, down:N or settings.
     */
    public function change()
    {
        $id = (string) $this->request->get('id', false);
        $back = '/admin/apache/local/rewrite/scope?id=' . urlencode($id);

        try {
            $scope = $this->editor->scope($id);
            [$op, $n] = array_pad(explode(':', (string) $this->request->get('op', false), 2), 2, null);
            $text = match ($op) {
                'delete' => $this->editor->withoutRule($scope, (int) $n),
                'up' => $this->editor->withRuleMoved($scope, (int) $n, -1),
                'down' => $this->editor->withRuleMoved($scope, (int) $n, 1),
                'settings' => $this->editor->withSettings($scope, (bool) $this->request->get('engine'), trim((string) $this->request->get('base', false))),
                default => throw new DomainException('Unknown change.'),
            };

            $refused = $this->confirmIdentity();

            if ($refused !== null) {
                throw new DomainException($refused);
            }

            $this->editor->save($this->authContext()->user, $scope, (string) $this->request->get('hash', false), $text);
        } catch (DomainException $e) {
            $this->response->withFlash('error', $e->getMessage())->redirect($back);

            return;
        }

        $this->response->withFlash('notice', 'Saved' . ($scope['kind'] === 'htaccess' ? '; it applies right away.' : '. Reload Apache to apply it.'))->redirect($back);
    }

    /**
     * ?url=...: where that URL ends up, step by step.
     */
    public function simulate()
    {
        $url = trim((string) $this->request->get('url', false));

        $this->response->view('apache.simulate', [
            'auth' => $this->authContext(),
            'url' => $url,
            'form' => $this->simulationForm(),
            'trace' => $url === '' ? null : $this->editor->simulator()->simulate($url, $this->simulationRequest()),
        ]);
    }

    /**
     * @return array{conds: list<array{test: string, pattern: string, flags: array<string, true>}>, pattern: string,
     *               substitution: string, flags: list<array{0: string, 1: ?string}>}
     */
    private function ruleFromForm(): array
    {
        $conds = [];

        foreach ((array) $this->request->get('conds', false) as $cond) {
            if (!is_array($cond) || (trim((string) ($cond['test'] ?? '')) === '' && trim((string) ($cond['pattern'] ?? '')) === '')) {
                continue;
            }

            $conds[] = [
                'test' => trim((string) ($cond['test'] ?? '')),
                'pattern' => trim((string) ($cond['pattern'] ?? '')),
                'flags' => array_fill_keys(array_values(array_filter(['NC', 'OR', 'NV'], fn ($f) => !empty($cond[strtolower($f)]))), true),
            ];
        }

        $flags = [];
        $chosen = (array) $this->request->get('flags', false);
        $values = (array) $this->request->get('flag_values', false);

        foreach (array_keys(RewriteRules::RULE_FLAGS) as $flag) {
            if (!empty($chosen[$flag])) {
                $value = trim((string) ($values[$flag] ?? ''));
                $flags[] = [$flag, $value === '' ? null : $value];
            }
        }

        return [
            'conds' => $conds,
            'pattern' => trim((string) $this->request->get('pattern', false)),
            'substitution' => trim((string) $this->request->get('substitution', false)),
            'flags' => $flags,
        ];
    }

    private function ruleIndex(): ?int
    {
        $index = $this->request->get('index');

        return $index === null || $index === '' ? null : (int) $index;
    }

    /**
     * @return array<string, string>
     */
    private function simulationForm(): array
    {
        return [
            'method' => strtoupper(trim((string) $this->request->get('method', false))) ?: 'GET',
            'user_agent' => (string) $this->request->get('user_agent', false),
            'referer' => (string) $this->request->get('referer', false),
            'cookie' => (string) $this->request->get('cookie', false),
            'remote_addr' => trim((string) $this->request->get('remote_addr', false)) ?: '127.0.0.1',
        ];
    }

    /**
     * @return array{method: string, headers: array<string, string>, remote_addr: string}
     */
    private function simulationRequest(): array
    {
        $form = $this->simulationForm();

        return [
            'method' => preg_match('/^[A-Z]{3,10}$/', $form['method']) === 1 ? $form['method'] : 'GET',
            'headers' => array_filter(['user-agent' => $form['user_agent'], 'referer' => $form['referer'], 'cookie' => $form['cookie']], fn ($v) => $v !== ''),
            'remote_addr' => filter_var($form['remote_addr'], FILTER_VALIDATE_IP) !== false ? $form['remote_addr'] : '127.0.0.1',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function renderScope(array $scope, ?string $notice = null, ?string $error = null): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.rewrite-scope', ['auth' => $this->authContext(), 'scope' => $scope, 'notice' => $notice, 'error' => $error] + $this->confirmFields());
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $rule
     * @param array<string, mixed>|null $trace
     */
    private function renderRule(array $scope, ?int $index, array $rule, ?string $error = null, ?array $trace = null, string $testUrl = ''): void
    {
        $this->response->withHeader('Cache-Control', 'no-store');
        $this->response->view('apache.rewrite-rule', [
            'auth' => $this->authContext(), 'scope' => $scope, 'index' => $index, 'rule' => $rule, 'error' => $error, 'trace' => $trace,
            'testUrl' => $testUrl, 'form' => $this->simulationForm(),
        ] + $this->confirmFields(), $error === null ? 200 : 422);
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
