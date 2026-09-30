<?php

namespace App\Services;

use App\DTOs\ApacheNode;
use App\Models\User;
use DomainException;

/**
 * This machine's whole Apache configuration as Apache reads it: apache2.conf
 * line by line, each Include/IncludeOptional opened where it is (nested, in
 * the order Apache reads the files, a file already read not again), with
 * what doesn't apply greyed (a false <IfModule>, and includes inside it,
 * which Apache doesn't follow). Sites, conf snippets and modules appear where
 * their Include brings them in, with those not enabled listed there; each
 * file header has its actions (edit, disable), <VirtualHost> and <Directory>
 * lines link to their editors. Single-line directive values in files the
 * root helper writes can be changed in place (save()).
 */
class ApacheConfigService
{
    /**
     * @var array<string, array<int, ApacheNode>> file => line => the directive or section starting there
     */
    private array $nodes = [];

    /**
     * @var array<string, list<array{0: int, 1: int}>> file => line ranges of sections that don't apply
     */
    private array $inactive = [];

    public function __construct(
        private readonly RewriteEditorService $config = new RewriteEditorService(),
        private readonly LocalApacheService $apache = new LocalApacheService(),
        private readonly string $root = '/etc/apache2',
    ) {
    }

    /**
     * @param array<string, mixed>|null $overview the helper's list (sites, confs, mods with enabled), for "not enabled"
     * @return list<array<string, mixed>> rows: file (a file starts), line, disabled (items not enabled), note, end (a file ends)
     */
    public function rows(?array $overview = null): array
    {
        $this->index();
        $rows = [];
        $read = [];
        $this->file("{$this->root}/apache2.conf", 0, $rows, $read, $overview ?? []);

        return $rows;
    }

    /**
     * Save changed values: file => [line => new value], each file only if it's still what the page saw
     * (hashes: file => sha256 of its text then). One write per file through the helper; stops at the first refusal.
     *
     * @param array<string, array<int, string>> $changes
     * @param array<string, string> $hashes
     * @return list<string> the files saved
     *
     * @throws DomainException
     */
    public function save(User $admin, array $changes, array $hashes): array
    {
        $this->index();
        $saved = [];

        foreach ($changes as $file => $edits) {
            $text = $this->config->tree()->contents((string) $file);

            if ($text === null || !isset($this->nodes[$file])) {
                throw new DomainException("$file isn't part of the configuration.");
            }

            $lines = ApacheConfigTree::lines($text);
            $changed = false;

            foreach ((array) $edits as $line => $value) {
                $line = (int) $line;
                $value = trim((string) $value);
                $node = $this->nodes[$file][$line] ?? null;
                [$name, $current] = $this->split($lines[$line - 1] ?? '');

                if ($node === null || $node->kind !== 'directive' || $value === $current) {
                    continue;
                }

                if ($this->config->fileKind((string) $file) === null || $node->endLine !== $node->line) {
                    throw new DomainException($this->short((string) $file) . ":$line can't be edited here.");
                }

                if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || str_starts_with($value, '<') || str_ends_with($value, '\\')) {
                    throw new DomainException($this->short((string) $file) . ":$line: $name needs a value on one line (to remove a line, edit the file).");
                }

                $indent = preg_match('/^(\s*)/', $lines[$line - 1], $m) === 1 ? $m[1] : '';
                $lines[$line - 1] = "$indent$name $value";
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            if (!hash_equals(hash('sha256', $text), (string) ($hashes[$file] ?? ''))) {
                throw new DomainException($this->short((string) $file) . ' changed since you opened the page; it wasn\'t saved. Reload and make the change again.' . ($saved === [] ? '' : ' Saved already: ' . implode(', ', $saved) . '.'));
            }

            [$kind, $name] = $this->config->fileKind((string) $file) ?? throw new DomainException('Not editable.');

            try {
                $this->apache->write($admin, $kind, $name, implode("\n", $lines));
            } catch (DomainException $e) {
                throw new DomainException($this->short((string) $file) . ': ' . $e->getMessage() . ($saved === [] ? '' : "\nSaved already: " . implode(', ', $saved) . '.'));
            }

            $saved[] = $this->short((string) $file);
        }

        return $saved;
    }

    public function short(string $file): string
    {
        return str_starts_with($file, "{$this->root}/") ? substr($file, strlen($this->root) + 1) : $file;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, true> $read
     * @param array<string, mixed> $overview
     */
    private function file(string $file, int $depth, array &$rows, array &$read, array $overview): void
    {
        $text = (string) $this->config->tree()->contents($file);
        $read[$file] = true;
        $rows[] = ['type' => 'file', 'depth' => $depth, 'file' => $file, 'short' => $this->short($file), 'hash' => hash('sha256', $text),
            'edit' => $this->config->fileKind($file), 'disable' => $this->enabledItem($file)];
        $lines = ApacheConfigTree::lines($text);

        if (end($lines) === '') {
            array_pop($lines);
        }

        foreach ($lines as $i => $raw) {
            $number = $i + 1;
            $node = $this->nodes[$file][$number] ?? null;
            $trimmed = trim($raw);
            $kind = match (true) {
                $trimmed === '' => 'blank',
                $trimmed[0] === '#' => 'comment',
                str_starts_with($trimmed, '</') => 'close',
                $trimmed[0] === '<' => 'open',
                $node === null => 'continued',
                default => 'directive',
            };
            [$name, $value] = $kind === 'directive' ? $this->split($raw) : ['', ''];
            $row = [
                'type' => 'line', 'depth' => $depth, 'file' => $file, 'line' => $number, 'text' => $raw, 'kind' => $kind,
                'active' => ($node === null || $node->active) && !$this->inInactive($file, $number, $node),
                'editable' => $kind === 'directive' && $node !== null && $node->endLine === $node->line && $this->config->fileKind($file) !== null,
                'name' => $name, 'value' => $value, 'links' => $kind === 'open' && $node !== null ? $this->links($node) : [],
            ];
            $rows[] = $row;

            if ($node === null || $node->kind !== 'directive' || !in_array($node->name, ['include', 'includeoptional'], true)) {
                continue;
            }

            if (!$row['active']) {
                $rows[] = ['type' => 'note', 'depth' => $depth + 1, 'text' => 'Not followed: this Include is inside a section that doesn\'t apply.'];

                continue;
            }

            $included = $this->config->tree()->included["$file:$number"] ?? [];

            foreach ($included as $inner) {
                if (isset($read[$inner])) {
                    $rows[] = ['type' => 'note', 'depth' => $depth + 1, 'text' => $this->short($inner) . ' was read above; Apache doesn\'t read it again.'];
                } else {
                    $this->file($inner, $depth + 1, $rows, $read, $overview);
                }
            }

            if ($included === []) {
                $rows[] = ['type' => 'note', 'depth' => $depth + 1, 'text' => 'Matches no files.'];
            }

            $disabled = $this->notEnabled($node->arg(), $overview);

            if ($disabled !== null) {
                $rows[] = ['type' => 'disabled', 'depth' => $depth + 1] + $disabled;
            }
        }

        $rows[] = ['type' => 'end', 'depth' => $depth, 'file' => $file];
    }

    /**
     * After "IncludeOptional sites-enabled/*.conf" and the like: the sites (conf snippets, modules) not enabled.
     *
     * @param array<string, mixed> $overview
     * @return array{kind: string, items: list<string>}|null
     */
    private function notEnabled(string $pattern, array $overview): ?array
    {
        [$kind, $list] = match (true) {
            str_contains($pattern, 'sites-enabled/') => ['site', 'sites'],
            str_contains($pattern, 'conf-enabled/') => ['conf', 'confs'],
            str_contains($pattern, 'mods-enabled/') && str_ends_with($pattern, '.load') => ['mod', 'mods'],
            default => [null, null],
        };

        if ($kind === null || !isset($overview[$list])) {
            return null;
        }

        return ['kind' => $kind, 'items' => array_values(array_map(fn ($i) => (string) $i['name'], array_filter((array) $overview[$list], fn ($i) => empty($i['enabled']))))];
    }

    /**
     * For an enabled site/snippet/module link (sites-enabled/NAME.conf...): what to disable. A module by its .load file.
     *
     * @return array{kind: string, name: string}|null
     */
    private function enabledItem(string $file): ?array
    {
        return match (true) {
            preg_match('#/sites-enabled/([^/]+)\.conf$#', $file, $m) === 1 => ['kind' => 'site', 'name' => $m[1]],
            preg_match('#/conf-enabled/([^/]+)\.conf$#', $file, $m) === 1 => ['kind' => 'conf', 'name' => $m[1]],
            preg_match('#/mods-enabled/([^/]+)\.load$#', $file, $m) === 1 => ['kind' => 'mod', 'name' => $m[1]],
            default => null,
        };
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function links(ApacheNode $node): array
    {
        $links = [];

        if ($node->isBlock('virtualhost')) {
            $links[] = ['label' => 'Edit virtual host', 'href' => '/admin/vhost?id=' . urlencode("local:{$node->file}:{$node->line}")];
        }

        if ($node->isBlock('virtualhost', 'directory', 'directorymatch')) {
            $links[] = ['label' => 'Rewrite and access rules', 'href' => '/admin/apache/local/rewrite/scope?id=' . urlencode("block:{$node->file}:{$node->line}")];
        }

        return $links;
    }

    /**
     * Every node by file and line, and the line ranges of sections that don't apply.
     */
    private function index(): void
    {
        if ($this->nodes !== []) {
            return;
        }

        $walk = function (ApacheNode $node) use (&$walk) {
            foreach ($node->children as $child) {
                $this->nodes[$child->file][$child->line] ??= $child;

                if ($child->kind === 'block') {
                    if (!$child->active) {
                        $this->inactive[$child->file][] = [$child->line, $child->endLine];
                    }

                    $walk($child);
                }
            }
        };
        $walk($this->config->top());
    }

    private function inInactive(string $file, int $line, ?ApacheNode $node): bool
    {
        foreach ($this->inactive[$file] ?? [] as [$from, $to]) {
            if ($line >= $from && $line <= $to) {
                return true;
            }
        }

        return $node !== null && !$node->active;
    }

    /**
     * A directive line as written: its name and the rest.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $line): array
    {
        return preg_match('/^\s*(\S+)\s*(.*?)\s*$/', $line, $m) === 1 ? [$m[1], $m[2]] : ['', ''];
    }
}
