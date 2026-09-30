<?php

namespace App\Services;

use App\DTOs\ApacheNode;
use App\Models\ApacheVhost;
use App\Models\Server;
use App\Models\User;
use DomainException;

/**
 * Editing one <VirtualHost> definition: on this machine (through the root
 * helper, LocalApacheService) or on a monitored server (over SSH with the
 * SSH user's sudo rules, RemoteApacheService). Either with fields for the
 * usual directives, which change, add or remove only their own lines, or
 * as the text of just that block. The rest of the file stays as it was.
 */
class VhostEditorService
{
    /**
     * The single-value directives the form edits, field => directive.
     *
     * @var array<string, string>
     */
    public const SIMPLE = [
        'server_name' => 'ServerName',
        'document_root' => 'DocumentRoot',
        'error_log' => 'ErrorLog',
        'ssl_certificate' => 'SSLCertificateFile',
        'ssl_key' => 'SSLCertificateKeyFile',
        'ssl_chain' => 'SSLCertificateChainFile',
    ];

    private const HOST = '/^(?=.{1,253}$)(\*\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/i';

    public function __construct(
        private readonly RewriteEditorService $local = new RewriteEditorService(),
        private readonly RemoteApacheService $remote = new RemoteApacheService(),
        private readonly LocalApacheService $apache = new LocalApacheService(),
    ) {
    }

    /**
     * This machine's virtual hosts, for the list on "Apache on this server".
     *
     * @return list<array{id: string, title: string, where: string}>
     */
    public function localVhosts(): array
    {
        $out = [];

        foreach ($this->local->top()->effective() as $node) {
            if ($node->isBlock('virtualhost')) {
                $names = array_merge(...array_map(fn ($n) => $n->args, array_filter($this->children($node), fn ($n) => in_array($n->name, ['servername', 'serveralias'], true))));
                $out[] = ['id' => "local:{$node->file}:{$node->line}", 'title' => implode(', ', $names) ?: '(no ServerName)', 'where' => $node->where() . ' · ' . implode(' ', $node->args)];
            }
        }

        return $out;
    }

    /**
     * Load a definition: "local:FILE:LINE" or "vhost:ID" (a monitored server's, from the Vhosts list).
     *
     * @return array<string, mixed> id, title, server (?Server), file, text, hash, block (ApacheNode), fields, write (?string), caps (?array)
     */
    public function load(string $id): array
    {
        if (preg_match('/^local:(.+):(\d+)$/', $id, $m) === 1) {
            foreach ($this->local->top()->effective() as $node) {
                if ($node->isBlock('virtualhost') && $node->file === $m[1] && $node->line === (int) $m[2]) {
                    $text = (string) $this->local->tree()->contents($node->file);

                    return $this->describe($id, null, $node->file, $text, $this->local->fileKind($node->file) !== null ? 'helper' : null, null, $node);
                }
            }

            throw new DomainException('That virtual host is gone (the configuration changed).');
        }

        if (preg_match('/^vhost:(\d+)$/', $id, $m) === 1) {
            $vhost = ApacheVhost::query()->find((int) $m[1]);
            $server = $vhost?->server;

            if (!$vhost instanceof ApacheVhost || !$server instanceof Server) {
                throw new DomainException('That virtual host is gone.');
            }

            if ($vhost->config_file === null) {
                throw new DomainException('The last scan of ' . $server->name . ' didn\'t say which file this virtual host is in (Apache 2.2, or a configuration read from a single file). Edit it on the server.');
            }

            if ($server->apache_container !== null) {
                throw new DomainException("Apache on {$server->name} runs in a container ({$server->apache_container}); its configuration isn't edited from here.");
            }

            $caps = $this->remote->capabilities($server);
            $read = $this->remote->readFile($server, $vhost->config_file);
            $block = $this->find($read['text'], $vhost->config_file, $vhost->address, $vhost->name);

            return $this->describe($id, $server, $vhost->config_file, $read['text'], $read['write'], $caps, $block);
        }

        throw new DomainException('Unknown virtual host.');
    }

    /**
     * The file's text with the form's fields applied to the block.
     *
     * @param array<string, mixed> $loaded from load()
     * @param array<string, string> $input
     *
     * @throws DomainException
     */
    public function withFields(array $loaded, array $input): string
    {
        /** @var ApacheNode $block */
        $block = $loaded['block'];
        $lines = ApacheConfigTree::lines($loaded['text']);
        $children = $this->children($block);
        $indent = $this->childIndent($lines, $block, $children);
        $edits = [];
        $inserts = [];

        $address = trim(preg_replace('/\s+/', ' ', (string) ($input['address'] ?? '')) ?? '');

        foreach (explode(' ', $address) as $token) {
            if (preg_match('/^(\*|_default_|\[[0-9a-f:]+\]|[A-Za-z0-9.-]+)(:(\d{1,5}|\*))?$/', $token) !== 1) {
                throw new DomainException('The address is like *:443, 192.0.2.10:80 or _default_:443 (several separated by spaces).');
            }
        }

        $open = $lines[$block->line - 1];
        $edits[$block->line] = [1, [(string) preg_replace('/^(\s*<VirtualHost)\s+[^>]*>/i', '$1 ' . $address . '>', $open)]];

        foreach (self::SIMPLE as $field => $directive) {
            $value = trim((string) ($input[$field] ?? ''));
            $this->checkValue($field, $value);
            $node = $this->firstNode($children, strtolower($directive));
            $line = $value === '' ? null : $directive . ' ' . RewriteRules::quote($value);

            if ($node !== null) {
                $edits[$node->line] = [$node->endLine - $node->line + 1, $line === null ? [] : [$this->indentOf($lines, $node->line) . $line]];
            } elseif ($line !== null) {
                $inserts[] = $indent . $line;
            }
        }

        // ServerAlias: one line with all of them, where the first one was.
        $aliases = array_values(array_filter(preg_split('/[\s,]+/', strtolower((string) ($input['aliases'] ?? ''))) ?: []));

        foreach ($aliases as $alias) {
            if (preg_match(self::HOST, $alias) !== 1) {
                throw new DomainException("\"$alias\" isn't a hostname.");
            }
        }

        $aliasNodes = array_values(array_filter($children, fn ($n) => $n->name === 'serveralias'));

        foreach ($aliasNodes as $i => $node) {
            $edits[$node->line] = [$node->endLine - $node->line + 1, $i === 0 && $aliases !== [] ? [$this->indentOf($lines, $node->line) . 'ServerAlias ' . implode(' ', $aliases)] : []];
        }

        if ($aliasNodes === [] && $aliases !== []) {
            $inserts[] = $indent . 'ServerAlias ' . implode(' ', $aliases);
        }

        // SSLEngine.
        $ssl = !empty($input['ssl_engine']);
        $engine = $this->firstNode($children, 'sslengine');

        if ($engine !== null) {
            $edits[$engine->line] = [$engine->endLine - $engine->line + 1, [$this->indentOf($lines, $engine->line) . 'SSLEngine ' . ($ssl ? 'on' : 'off')]];
        } elseif ($ssl) {
            $inserts[] = $indent . 'SSLEngine on';
        }

        if ($ssl && (trim((string) ($input['ssl_certificate'] ?? '')) === '' || trim((string) ($input['ssl_key'] ?? '')) === '')) {
            throw new DomainException('With SSL on, give the certificate and key files.');
        }

        // CustomLog: the first one's file, its format kept.
        $access = trim((string) ($input['access_log'] ?? ''));
        $this->checkValue('access_log', $access);
        $custom = $this->firstNode($children, 'customlog');

        if ($custom !== null) {
            $format = array_slice($custom->rawArgs ?: $custom->args, 1);
            $edits[$custom->line] = [$custom->endLine - $custom->line + 1, $access === '' ? [] : [$this->indentOf($lines, $custom->line) . 'CustomLog ' . RewriteRules::quote($access) . ($format === [] ? ' combined' : ' ' . implode(' ', array_map([RewriteRules::class, 'quote'], $format)))]];
        } elseif ($access !== '') {
            $inserts[] = $indent . 'CustomLog ' . RewriteRules::quote($access) . ' combined';
        }

        // Redirect everything to https.
        $https = trim((string) ($input['https_redirect'] ?? ''));

        if ($https !== '' && preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/?$#', $https) !== 1) {
            throw new DomainException('Redirect to https takes https://hostname/.');
        }

        $redirect = $this->httpsRedirect($children);
        $redirectLine = $https === '' ? null : 'Redirect permanent / ' . rtrim($https, '/') . '/';

        if ($redirect !== null) {
            $edits[$redirect->line] = [$redirect->endLine - $redirect->line + 1, $redirectLine === null ? [] : [$this->indentOf($lines, $redirect->line) . $redirectLine]];
        } elseif ($redirectLine !== null) {
            $inserts[] = $indent . $redirectLine;
        }

        // Bottom up, so the line numbers above stay right; new lines go just before </VirtualHost>.
        if ($inserts !== []) {
            array_splice($lines, $block->endLine - 1, 0, $inserts);
        }

        krsort($edits);

        foreach ($edits as $line => [$count, $replacement]) {
            array_splice($lines, $line - 1, $count, $replacement);
        }

        return implode("\n", $lines);
    }

    /**
     * The file's text with the block replaced by $blockText (which must be one <VirtualHost> section).
     *
     * @param array<string, mixed> $loaded
     *
     * @throws DomainException
     */
    public function withBlock(array $loaded, string $blockText): string
    {
        $blockText = rtrim(str_replace("\r\n", "\n", $blockText));

        if (str_contains($blockText, "\0") || strlen($blockText) > 200000) {
            throw new DomainException('That isn\'t a virtual host definition.');
        }

        $check = new ApacheConfigTree('/nonexistent');
        $root = new ApacheNode('block', 'check', [], 'block', 0, 0);
        $check->parseText($blockText, 'block', $root);
        $top = array_values(array_filter($root->children, fn (ApacheNode $n) => true));

        if (count($top) !== 1 || !$top[0]->isBlock('virtualhost') || $check->notes !== []) {
            throw new DomainException('The text must be exactly one <VirtualHost ...> ... </VirtualHost> section' . ($check->notes !== [] ? ': ' . implode(' ', $check->notes) : '.'));
        }

        /** @var ApacheNode $block */
        $block = $loaded['block'];
        $lines = ApacheConfigTree::lines($loaded['text']);
        array_splice($lines, $block->line - 1, $block->endLine - $block->line + 1, explode("\n", $blockText));

        return implode("\n", $lines);
    }

    /**
     * Save the new file text: the helper here, the SSH user's sudo rules there.
     *
     * @param array<string, mixed> $loaded
     * @return array<string, mixed>
     */
    public function save(User $admin, array $loaded, string $hash, string $text): array
    {
        if ($loaded['server'] instanceof Server) {
            $result = $this->remote->writeFile($admin, $loaded['server'], $loaded['file'], $hash, $text);
            // Scan again at the next check, so the Vhosts list follows.
            $loaded['server']->apache_scanned_at = null;
            $loaded['server']->save();

            return $result;
        }

        $current = (string) file_get_contents($loaded['file']);

        if (!hash_equals(hash('sha256', $current), $hash)) {
            throw new DomainException('The file changed since you opened it; nothing was saved. Reload and make the change again.');
        }

        [$kind, $name] = $this->local->fileKind($loaded['file']) ?? throw new DomainException('That file can\'t be edited here.');

        return $this->apache->write($admin, $kind, $name, $text);
    }

    public function simulator(string $file, string $text): ApacheSimulator
    {
        return $this->local->simulator($file, $text);
    }

    /**
     * @param array<string, mixed>|null $caps
     * @return array<string, mixed>
     */
    private function describe(string $id, ?Server $server, string $file, string $text, ?string $write, ?array $caps, ApacheNode $block): array
    {
        $lines = ApacheConfigTree::lines($text);
        $children = $this->children($block);
        $fields = ['address' => implode(' ', $block->rawArgs ?: $block->args)];

        foreach (self::SIMPLE as $field => $directive) {
            $fields[$field] = $this->firstNode($children, strtolower($directive))?->rawArg() ?? '';
        }

        $fields['aliases'] = implode(' ', array_merge(...array_map(fn ($n) => $n->rawArgs ?: $n->args, array_values(array_filter($children, fn ($n) => $n->name === 'serveralias'))) ?: [[]]));
        $fields['access_log'] = $this->firstNode($children, 'customlog')?->rawArg() ?? '';
        $fields['ssl_engine'] = strtolower($this->firstNode($children, 'sslengine')?->rawArg() ?? '') === 'on';
        $redirect = $this->httpsRedirect($children);
        $fields['https_redirect'] = $redirect === null ? '' : $redirect->rawArg(2);

        return [
            'id' => $id, 'server' => $server, 'file' => $file, 'text' => $text, 'hash' => hash('sha256', $text), 'block' => $block,
            'blockText' => implode("\n", array_slice($lines, $block->line - 1, $block->endLine - $block->line + 1)),
            'fields' => $fields, 'write' => $write, 'caps' => $caps,
            'title' => ($fields['server_name'] !== '' ? $fields['server_name'] : '(no ServerName)') . ' · ' . $fields['address'] . ($server !== null ? ' on ' . $server->name : ' on this machine'),
        ];
    }

    /**
     * The block in a file matching an address and ServerName (as the scan recorded them).
     */
    private function find(string $text, string $file, string $address, ?string $name): ApacheNode
    {
        $tree = new ApacheConfigTree('/nonexistent');
        $root = new ApacheNode('block', 'file', [], $file, 0, 0);
        $tree->parseText($text, $file, $root);
        $walk = function (ApacheNode $node) use (&$walk): array {
            $found = [];

            foreach ($node->children as $child) {
                if ($child->isBlock('virtualhost')) {
                    $found[] = $child;
                } elseif ($child->kind === 'block') {
                    array_push($found, ...$walk($child));
                }
            }

            return $found;
        };

        foreach ($walk($root) as $block) {
            $serverName = $this->firstNode($this->children($block), 'servername')?->arg();
            $serverName = $serverName === null ? null : strtolower((string) preg_replace(['#^[a-z]+://#i', '#:\d+$#', '#/.*$#'], '', $serverName));

            if (implode(' ', $block->args) === $address && $serverName === $name) {
                return $block;
            }
        }

        throw new DomainException("The virtual host isn't in $file any more (the configuration changed since the last scan). Rescan Apache for that server.");
    }

    /**
     * A block's directives, looking into <IfModule> and the like whatever their state (another server's modules aren't known here).
     *
     * @return list<ApacheNode>
     */
    private function children(ApacheNode $block, ?string $file = null): array
    {
        $file ??= $block->file;
        $out = [];

        foreach ($block->children as $child) {
            // Included files' directives aren't this block's lines: they're edited in their own file.
            if ($child->file !== $file) {
                continue;
            }

            if ($child->isBlock(...ApacheNode::CONDITIONALS)) {
                array_push($out, ...$this->children($child, $file));
            } elseif ($child->kind === 'directive') {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param list<ApacheNode> $children
     */
    private function firstNode(array $children, string $name): ?ApacheNode
    {
        foreach ($children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param list<ApacheNode> $children
     */
    private function httpsRedirect(array $children): ?ApacheNode
    {
        foreach ($children as $child) {
            if ($child->name === 'redirect' && count($child->args) === 3 && in_array(strtolower($child->arg(0)), ['permanent', '301'], true) && $child->arg(1) === '/' && str_starts_with(strtolower($child->arg(2)), 'https://')) {
                return $child;
            }
        }

        return null;
    }

    private function checkValue(string $field, string $value): void
    {
        if ($value === '') {
            return;
        }

        if ($field === 'server_name') {
            if (preg_match(self::HOST, $value) !== 1 || str_starts_with($value, '*')) {
                throw new DomainException('ServerName is a hostname, e.g. shop.example.com.');
            }

            return;
        }

        if (preg_match('#^(/|\$\{[A-Z_]+\}/)[A-Za-z0-9._/@+${}-]{0,500}$#', $value) !== 1 || str_contains($value, '/../')) {
            throw new DomainException(ucfirst(str_replace('_', ' ', $field)) . ' must be a full path (or start with a variable such as ${APACHE_LOG_DIR}/).');
        }
    }

    /**
     * @param list<string> $lines
     * @param list<ApacheNode> $children
     */
    private function childIndent(array $lines, ApacheNode $block, array $children): string
    {
        return $children !== [] ? $this->indentOf($lines, $children[0]->line) : $this->indentOf($lines, $block->line) . '    ';
    }

    /**
     * @param list<string> $lines
     */
    private function indentOf(array $lines, int $line): string
    {
        return preg_match('/^(\s*)/', $lines[$line - 1] ?? '', $m) === 1 ? $m[1] : '';
    }
}
