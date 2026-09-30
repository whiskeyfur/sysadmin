<?php

namespace App\Services;

use App\DTOs\ApacheNode;
use App\Models\User;
use DomainException;

/**
 * Editing mod_rewrite rules in this machine's Apache, one scope at a time:
 * a <VirtualHost> or <Directory> in the configuration, or a .htaccess file
 * in a served directory. Each change edits only the lines of the rule it's
 * about, in the file's text, so everything else (comments, other
 * directives, layout) stays as it was; then LocalApacheService writes it
 * through the root helper (config files are configtested and put back if
 * Apache refuses them). A file changed since the page was loaded isn't
 * overwritten.
 */
class RewriteEditorService
{
    /**
     * Directories not searched for .htaccess files.
     */
    private const SKIP = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tmp'];

    public const HTACCESS_DEPTH = 4;

    private ?ApacheConfigTree $tree = null;

    private ?ApacheNode $top = null;

    public function __construct(
        private readonly LocalApacheService $apache = new LocalApacheService(),
        private readonly string $root = '/etc/apache2',
    ) {
    }

    public function tree(): ApacheConfigTree
    {
        if ($this->tree === null) {
            $this->tree = new ApacheConfigTree($this->root, 'apache2.conf', self::apacheVersion());
            $this->top = $this->tree->load();
        }

        return $this->tree;
    }

    public function top(): ApacheNode
    {
        $this->tree();

        return $this->top ?? throw new \LogicException('Configuration not loaded.');
    }

    public static function apacheVersion(): ?string
    {
        exec('/usr/sbin/apache2 -v 2>/dev/null', $out);

        return preg_match('#Apache/([\d.]+)#', implode("\n", $out), $m) === 1 ? $m[1] : null;
    }

    /**
     * Every place rules can be: virtual hosts and <Directory> sections of the enabled configuration,
     * and .htaccess files (existing ones, and the served directories that could have one).
     *
     * @return list<array{id: string, kind: string, title: string, where: string, rules: int, engine: ?bool, access: int, exists: bool, editable: bool}>
     */
    public function scopes(): array
    {
        $scopes = [];

        foreach ($this->blocks($this->top()) as $block) {
            $set = RewriteRules::fromNodes($block->effective());
            $scopes[] = [
                'id' => 'block:' . $block->file . ':' . $block->line,
                'kind' => $block->name,
                'title' => $this->title($block),
                'where' => $block->where($this->root),
                'rules' => count($set->rules),
                'engine' => $set->engine,
                'access' => count(array_filter($block->effective(), fn (ApacheNode $n) => $n->file === $block->file && in_array($n->name, self::ACCESS_DIRECTIVES, true))),
                'exists' => true,
                'editable' => $this->fileKind($block->file) !== null,
            ];
        }

        foreach ($this->servedDirectories() as $root) {
            foreach ($this->htaccessDirs($root) as [$dir, $exists]) {
                $set = $exists ? $this->htaccessRules($dir) : new RewriteRules();
                $scopes[] = [
                    'id' => 'htaccess:' . $dir,
                    'kind' => 'htaccess',
                    'title' => "$dir/.htaccess",
                    'where' => $exists ? 'file' : 'none yet',
                    'rules' => count($set->rules),
                    'engine' => $set->engine,
                    'access' => $exists ? count($this->access(['file' => "$dir/.htaccess", 'text' => (string) $this->tree()->contents("$dir/.htaccess"), 'block' => null])) : 0,
                    'exists' => $exists,
                    'editable' => true,
                ];
            }
        }

        return $scopes;
    }

    /**
     * One scope: its file's text, and the rules in it.
     *
     * @return array{id: string, kind: string, title: string, file: string, text: string, hash: string, rules: RewriteRules,
     *               block: ?ApacheNode, exists: bool, editable: bool}
     */
    public function scope(string $id): array
    {
        if (str_starts_with($id, 'htaccess:')) {
            $dir = rtrim(substr($id, 9), '/');

            if (!in_array($dir, array_map(fn ($d) => $d[0], array_merge(...array_map(fn ($r) => $this->htaccessDirs($r), $this->servedDirectories()))), true)
                && !$this->insideServed($dir)) {
                throw new DomainException('That directory isn\'t served by Apache.');
            }

            $file = "$dir/.htaccess";
            $text = is_file($file) ? (string) $this->tree()->contents($file) : '';

            return [
                'id' => $id, 'kind' => 'htaccess', 'title' => $file, 'file' => $file, 'text' => $text, 'hash' => hash('sha256', $text),
                'rules' => $this->htaccessRules($dir, $text), 'block' => null, 'exists' => is_file($file), 'editable' => true,
            ];
        }

        if (preg_match('/^block:(.+):(\d+)$/', $id, $m) !== 1) {
            throw new DomainException('Unknown place.');
        }

        foreach ($this->blocks($this->top()) as $block) {
            if ($block->file === $m[1] && $block->line === (int) $m[2]) {
                $text = (string) $this->tree()->contents($block->file);

                return [
                    'id' => $id, 'kind' => $block->name, 'title' => $this->title($block), 'file' => $block->file, 'text' => $text,
                    'hash' => hash('sha256', $text), 'rules' => RewriteRules::fromNodes($this->ownDirectives($block), true), 'block' => $block,
                    'exists' => true, 'editable' => $this->fileKind($block->file) !== null,
                ];
            }
        }

        throw new DomainException('That section is gone (the configuration changed). Pick it again from the list.');
    }

    public const ACCESS_DIRECTIVES = ['require', 'order', 'allow', 'deny', 'satisfy'];

    /**
     * The access control lines of a scope (Require, Order, Allow, Deny, Satisfy, also inside <RequireAll/Any/None>
     * and <IfModule>), in its own file, each with its line.
     *
     * @param array<string, mixed> $scope
     * @return list<array{line: int, text: string, context: string}>
     */
    public function access(array $scope): array
    {
        $lines = ApacheConfigTree::lines($scope['text']);
        $nodes = [];
        $walk = function (ApacheNode $node, string $context) use (&$walk, &$nodes, $scope, $lines) {
            foreach ($node->children as $child) {
                if ($child->file !== $scope['file']) {
                    continue;
                }

                if ($child->kind === 'block' && ($child->isBlock('requireall', 'requireany', 'requirenone') || $child->isBlock(...ApacheNode::CONDITIONALS))) {
                    $walk($child, trim($context . ' <' . $child->name . ($child->args === [] ? '' : ' ' . implode(' ', $child->rawArgs)) . '>'));
                } elseif ($child->kind === 'directive' && in_array($child->name, self::ACCESS_DIRECTIVES, true)) {
                    $nodes[] = ['line' => $child->line, 'text' => trim($lines[$child->line - 1] ?? ''), 'context' => $context];
                }
            }
        };

        if ($scope['block'] instanceof ApacheNode) {
            $walk($scope['block'], '');
        } else {
            $block = new ApacheNode('block', 'htaccess', [], $scope['file'], 0, 0);
            $this->tree()->parseText($scope['text'], $scope['file'], $block);
            $walk($block, '');
        }

        return $nodes;
    }

    /**
     * The file's text with access lines changed in place (an empty one removed) and new ones added.
     *
     * @param array<string, mixed> $scope
     * @param array<int, string> $changes line number => new text ('' removes it)
     *
     * @throws DomainException
     */
    public function withAccess(array $scope, array $changes, string $added): string
    {
        $known = array_column($this->access($scope), null, 'line');
        $lines = ApacheConfigTree::lines($scope['text']);

        foreach ($changes as $line => $text) {
            if (!isset($known[$line])) {
                throw new DomainException('That line isn\'t an access line any more. Reload the page.');
            }

            $this->checkAccessLine($text, true);
        }

        $new = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $added) ?: []), fn ($l) => trim($l) !== ''));
        $depth = 0;

        foreach ($new as $text) {
            $this->checkAccessLine(trim($text), false);
            $depth += preg_match('#^\s*<Require(All|Any|None)>\s*$#i', $text) === 1 ? 1 : (preg_match('#^\s*</Require(All|Any|None)>\s*$#i', $text) === 1 ? -1 : 0);

            if ($depth < 0) {
                throw new DomainException('A </RequireAll>-style line closes nothing.');
            }
        }

        if ($depth !== 0) {
            throw new DomainException('Every <RequireAll>, <RequireAny> or <RequireNone> needs its closing line.');
        }

        krsort($changes);

        foreach ($changes as $line => $text) {
            $indent = preg_match('/^(\s*)/', $lines[$line - 1], $m) === 1 ? $m[1] : '';
            array_splice($lines, $line - 1, 1, trim($text) === '' ? [] : [$indent . trim($text)]);
        }

        if ($new !== []) {
            // After the scope's own settings (before its rewrite rules): the last existing access line, else where rules would go.
            $remaining = array_diff_key($known, array_filter($changes, fn ($t) => trim($t) === ''));
            $removedAbove = fn (int $line) => count(array_filter(array_keys($changes), fn ($l) => $l < $line && trim($changes[$l]) === ''));

            if ($remaining !== []) {
                $last = max(array_keys($remaining));
                $at = $last - $removedAbove($last);
                $indent = preg_match('/^(\s*)/', $lines[$at - 1], $m) === 1 ? $m[1] : '';
            } elseif ($scope['block'] instanceof ApacheNode) {
                // Right after the section's opening line.
                $at = $scope['block']->line;
                $indent = (preg_match('/^(\s*)/', $lines[$at - 1], $m) === 1 ? $m[1] : '') . '    ';
            } else {
                // The top of a .htaccess file.
                [$at, $indent] = [0, ''];
            }

            array_splice($lines, $at, 0, array_map(fn ($l) => $indent . trim($l), $new));
        }

        return $this->ensureNewline(implode("\n", $lines));
    }

    private function checkAccessLine(string $text, bool $mayBeEmpty): void
    {
        $text = trim($text);

        if ($text === '' && $mayBeEmpty) {
            return;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $text) === 1
            || preg_match('#^(Require\s+\S.*|Order\s+(deny,\s*allow|allow,\s*deny|mutual-failure)|(Allow|Deny)\s+from\s+\S.*|Satisfy\s+(All|Any)|</?Require(All|Any|None)>)$#i', $text) !== 1) {
            throw new DomainException("\"$text\" isn't an access line (Require ..., Order deny,allow, Allow from ..., Deny from ..., Satisfy Any, <RequireAll>...).");
        }
    }

    /**
     * The file's text with a rule replaced ($index), or added at the end ($index null).
     *
     * @param array<string, mixed> $scope from scope()
     * @param array{conds: list<array{test: string, pattern: string, flags: array<string, true>}>, pattern: string,
     *              substitution: string, flags: list<array{0: string, 1: ?string}>} $rule
     *
     * @throws DomainException
     */
    public function withRule(array $scope, ?int $index, array $rule): string
    {
        $problem = RewriteRules::problem($rule);

        if ($problem !== null) {
            throw new DomainException($problem);
        }

        /** @var RewriteRules $set */
        $set = $scope['rules'];
        $lines = ApacheConfigTree::lines($scope['text']);

        if ($index !== null) {
            $old = $set->rules[$index] ?? throw new DomainException('That rule is gone. Reload the page.');
            $indent = $this->indentOf($lines, $old['start']);
            array_splice($lines, $old['start'] - 1, $old['end'] - $old['start'] + 1, RewriteRules::lines($rule, $indent));

            return implode("\n", $lines);
        }

        [$at, $indent] = $this->insertionPoint($scope, $lines);
        $new = RewriteRules::lines($rule, $indent);

        if ($set->engine !== true) {
            array_unshift($new, $indent . 'RewriteEngine On');
        }

        array_splice($lines, $at, 0, $new);

        return $this->ensureNewline(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function withoutRule(array $scope, int $index): string
    {
        /** @var RewriteRules $set */
        $set = $scope['rules'];
        $old = $set->rules[$index] ?? throw new DomainException('That rule is gone. Reload the page.');
        $lines = ApacheConfigTree::lines($scope['text']);
        array_splice($lines, $old['start'] - 1, $old['end'] - $old['start'] + 1);

        return implode("\n", $lines);
    }

    /**
     * Swap a rule with the one before ($direction -1) or after (+1); the lines between them stay put.
     *
     * @param array<string, mixed> $scope
     */
    public function withRuleMoved(array $scope, int $index, int $direction): string
    {
        /** @var RewriteRules $set */
        $set = $scope['rules'];
        $other = $index + $direction;

        if (!isset($set->rules[$index], $set->rules[$other])) {
            throw new DomainException('It can\'t move further.');
        }

        [$first, $second] = $direction < 0 ? [$set->rules[$other], $set->rules[$index]] : [$set->rules[$index], $set->rules[$other]];

        if ($first['node']->file !== $second['node']->file) {
            throw new DomainException('Those rules are in different files.');
        }

        $lines = ApacheConfigTree::lines($scope['text']);
        $firstText = array_slice($lines, $first['start'] - 1, $first['end'] - $first['start'] + 1);
        $secondText = array_slice($lines, $second['start'] - 1, $second['end'] - $second['start'] + 1);
        $between = array_slice($lines, $first['end'], $second['start'] - $first['end'] - 1);
        array_splice($lines, $first['start'] - 1, $second['end'] - $first['start'] + 1, [...$secondText, ...$between, ...$firstText]);

        return implode("\n", $lines);
    }

    /**
     * RewriteEngine On/Off, and RewriteBase (empty: none).
     *
     * @param array<string, mixed> $scope
     */
    public function withSettings(array $scope, bool $engine, ?string $base): string
    {
        if ($base !== null && $base !== '' && preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@/%-]*$#', $base) !== 1) {
            throw new DomainException('RewriteBase is a URL path starting with /.');
        }

        /** @var RewriteRules $set */
        $set = $scope['rules'];
        $lines = ApacheConfigTree::lines($scope['text']);
        [$at, $indent] = $this->insertionPoint($scope, $lines, before: true);
        $baseLine = $base === null || $base === '' ? null : 'RewriteBase ' . RewriteRules::quote($base);

        // From the bottom up, so earlier line numbers stay valid.
        $edits = [];

        if ($set->baseNode !== null) {
            $edits[$set->baseNode->line] = $baseLine === null ? null : $this->indentOf($lines, $set->baseNode->line) . $baseLine;
        } elseif ($baseLine !== null) {
            $insertBase = $indent . $baseLine;
        }

        if ($set->engineNode !== null) {
            $edits[$set->engineNode->line] = $this->indentOf($lines, $set->engineNode->line) . 'RewriteEngine ' . ($engine ? 'On' : 'Off');
        } else {
            $insertEngine = $indent . 'RewriteEngine ' . ($engine ? 'On' : 'Off');
        }

        krsort($edits);

        foreach ($edits as $line => $text) {
            if ($text === null) {
                array_splice($lines, $line - 1, 1);

                if ($line - 1 < $at) {
                    $at--;
                }
            } else {
                $lines[$line - 1] = $text;
            }
        }

        $insert = array_values(array_filter([$insertEngine ?? null, $insertBase ?? null]));

        if ($insert !== []) {
            array_splice($lines, $at, 0, $insert);
        }

        return $this->ensureNewline(implode("\n", $lines));
    }

    /**
     * Write the new text: through the helper, after checking the file wasn't changed meanwhile.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function save(User $admin, array $scope, string $hash, string $text): array
    {
        // The file as it is now, not as the page saw it.
        $current = is_file($scope['file']) ? (string) file_get_contents($scope['file']) : '';

        if (!hash_equals(hash('sha256', $current), $hash)) {
            throw new DomainException('The file changed since you opened it; nothing was saved. Reload and make the change again.');
        }

        if ($scope['kind'] === 'htaccess') {
            return $this->apache->writeHtaccess($admin, dirname($scope['file']), $text, !$scope['exists']);
        }

        [$kind, $name] = $this->fileKind($scope['file']) ?? throw new DomainException('That file can\'t be edited here (only sites, conf snippets, apache2.conf and ports.conf).');

        return $this->apache->write($admin, $kind, $name, $text);
    }

    /**
     * A fresh simulator; with $file/$text, one that reads that file as if it held the new text.
     */
    public function simulator(?string $file = null, ?string $text = null): ApacheSimulator
    {
        $tree = new ApacheConfigTree($this->root, 'apache2.conf', self::apacheVersion());

        if ($file !== null && $text !== null) {
            $tree->override($file, $text);
        }

        return new ApacheSimulator($tree->load(), $tree);
    }

    /**
     * The helper's kind and name for a configuration file, or null if it can't write it.
     *
     * @return array{0: string, 1: string}|null
     */
    public function fileKind(string $file): ?array
    {
        $real = realpath($file) ?: $file;
        $root = realpath($this->root) ?: $this->root;

        return match (true) {
            $real === "$root/apache2.conf" => ['main', ''],
            $real === "$root/ports.conf" => ['ports', ''],
            preg_match('#^' . preg_quote($root, '#') . '/(sites|conf)-available/([^/]+)\.conf$#', $real, $m) === 1 => [$m[1] === 'sites' ? 'site' : 'conf', $m[2]],
            default => null,
        };
    }

    /**
     * @return list<string> DocumentRoot and Alias targets of the enabled configuration
     */
    public function servedDirectories(): array
    {
        $dirs = [];
        $walk = function (ApacheNode $node) use (&$walk, &$dirs) {
            foreach ($node->effective() as $child) {
                if ($child->kind === 'directive' && in_array($child->name, ['documentroot', 'alias', 'scriptalias'], true)) {
                    $dir = realpath(rtrim($child->name === 'documentroot' ? $child->arg() : $child->arg(1), '/'));

                    if ($dir !== false && is_dir($dir) && $dir !== '/') {
                        $dirs[] = $dir;
                    }
                } elseif ($child->kind === 'block') {
                    $walk($child);
                }
            }
        };
        $walk($this->top());
        $dirs = array_values(array_unique($dirs));
        sort($dirs);

        return $dirs;
    }

    private function insideServed(string $dir): bool
    {
        $real = realpath($dir);

        if ($real === false || $real !== $dir) {
            return false;
        }

        foreach ($this->servedDirectories() as $root) {
            if ($dir === $root || str_starts_with($dir, "$root/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * The served root itself (whether or not it has a .htaccess) and directories below it that have one.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function htaccessDirs(string $root): array
    {
        $found = [[$root, is_file("$root/.htaccess")]];
        $search = function (string $dir, int $depth) use (&$search, &$found) {
            if ($depth > self::HTACCESS_DEPTH || !is_readable($dir)) {
                return;
            }

            foreach (scandir($dir) ?: [] as $entry) {
                $path = "$dir/$entry";

                if ($entry === '.' || $entry === '..' || $entry[0] === '.' || in_array($entry, self::SKIP, true) || !is_dir($path) || is_link($path)) {
                    continue;
                }

                if (is_file("$path/.htaccess")) {
                    $found[] = [$path, true];
                }

                $search($path, $depth + 1);
            }
        };
        $search($root, 1);

        return $found;
    }

    private function htaccessRules(string $dir, ?string $text = null): RewriteRules
    {
        $block = new ApacheNode('block', 'htaccess', [], "$dir/.htaccess", 0, 0);
        $this->tree()->parseText($text ?? (string) $this->tree()->contents("$dir/.htaccess"), "$dir/.htaccess", $block);

        return RewriteRules::fromNodes($block->effective(), true);
    }

    /**
     * A section's directives from its own file only (an Include's lines belong to the included file).
     *
     * @return list<ApacheNode>
     */
    private function ownDirectives(ApacheNode $block): array
    {
        return array_values(array_filter($block->effective(), fn (ApacheNode $n) => $n->file === $block->file));
    }

    /**
     * @return list<ApacheNode> <VirtualHost> and <Directory> sections, top level and inside virtual hosts
     */
    private function blocks(ApacheNode $top): array
    {
        $blocks = [];

        foreach ($top->effective() as $node) {
            if ($node->isBlock('virtualhost')) {
                $blocks[] = $node;

                foreach ($node->effective() as $inner) {
                    if ($inner->isBlock('directory', 'directorymatch')) {
                        $blocks[] = $inner;
                    }
                }
            } elseif ($node->isBlock('directory', 'directorymatch')) {
                $blocks[] = $node;
            }
        }

        return $blocks;
    }

    private function title(ApacheNode $block): string
    {
        if ($block->isBlock('virtualhost')) {
            $names = [];

            foreach ($block->effective() as $node) {
                if (in_array($node->name, ['servername', 'serveralias'], true)) {
                    array_push($names, ...$node->args);
                }
            }

            return 'VirtualHost ' . implode(' ', $block->args) . ($names === [] ? '' : ' (' . implode(', ', array_slice($names, 0, 3)) . ')');
        }

        return ($block->parent !== null && $block->parent->isBlock('virtualhost') ? $this->title($block->parent) . ' › ' : '') . '<' . ucfirst($block->name) . ' ' . implode(' ', $block->args) . '>';
    }

    /**
     * Where to add lines in a scope, and with what indentation.
     *
     * @param array<string, mixed> $scope
     * @param list<string> $lines
     * @return array{0: int, 1: string} the index to insert at (0-based), and the indentation
     */
    private function insertionPoint(array $scope, array $lines, bool $before = false): array
    {
        /** @var RewriteRules $set */
        $set = $scope['rules'];
        $block = $scope['block'];

        if (!$before && $set->rules !== []) {
            $last = $set->rules[count($set->rules) - 1];

            return [$last['end'], $this->indentOf($lines, $last['node']->line)];
        }

        if ($before && $set->rules !== []) {
            $first = $set->rules[0];

            return [$first['start'] - 1, $this->indentOf($lines, $first['start'])];
        }

        $anchor = $set->baseNode ?? $set->engineNode;

        if ($anchor !== null) {
            return [$anchor->endLine, $this->indentOf($lines, $anchor->line)];
        }

        if ($block instanceof ApacheNode) {
            return [$block->endLine - 1, $this->indentOf($lines, $block->line) . '    '];
        }

        return [count($lines) - (end($lines) === '' ? 1 : 0), ''];
    }

    /**
     * @param list<string> $lines
     */
    private function indentOf(array $lines, int $line): string
    {
        return preg_match('/^(\s*)/', $lines[$line - 1] ?? '', $m) === 1 ? $m[1] : '';
    }

    private function ensureNewline(string $text): string
    {
        return str_ends_with($text, "\n") ? $text : "$text\n";
    }
}
