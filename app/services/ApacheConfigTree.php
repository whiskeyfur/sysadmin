<?php

namespace App\Services;

use App\DTOs\ApacheNode;

/**
 * This machine's Apache configuration as a tree (ApacheNode), read
 * straight from the files (the web server can read /etc/apache2): from
 * apache2.conf, following Include/IncludeOptional (files, whole
 * directories, wildcards; relative to ServerRoot) with each directive's
 * file and lines kept, so rules can be edited in place. ${VARIABLES} come
 * from Define lines and Debian's envvars. <IfModule>, <IfDefine>,
 * <IfVersion> and <IfFile> are decided as Apache decides them while
 * reading (modules loaded so far, including the ones compiled in); a false
 * one's contents are kept but marked inactive, and its includes aren't
 * followed.
 */
class ApacheConfigTree
{
    /**
     * Compiled into Ubuntu's apache2 (apache2 -l): always there for <IfModule>.
     */
    public const STATIC_MODULES = ['core', 'so', 'watchdog', 'http', 'log_config', 'logio', 'version', 'unixd'];

    public const MAX_FILES = 1000;

    /**
     * @var array<string, string>
     */
    public array $variables = [];

    /**
     * Loaded module names, e.g. "rewrite" (from rewrite_module / mod_rewrite.so).
     *
     * @var array<string, true>
     */
    public array $modules = [];

    /**
     * @var list<string> things that couldn't be read or understood
     */
    public array $notes = [];

    /**
     * @var list<string> every file read, in order
     */
    public array $files = [];

    private string $serverRoot;

    /**
     * Text to use instead of a file's (real path => text): "test before saving".
     *
     * @var array<string, string>
     */
    private array $overrides = [];

    /**
     * @param string|null $version e.g. "2.4.58", for <IfVersion>; unknown: counted as true
     */
    public function __construct(
        private readonly string $root = '/etc/apache2',
        private readonly string $main = 'apache2.conf',
        private readonly ?string $version = null,
    ) {
        $this->serverRoot = $root;
    }

    /**
     * Read $file as if it contained $text (for trying a change before saving it).
     */
    public function override(string $file, string $text): void
    {
        $this->overrides[realpath($file) ?: $file] = $text;
    }

    /**
     * A file's text (or its override), or null if it can't be read.
     */
    public function contents(string $file): ?string
    {
        $key = realpath($file) ?: $file;

        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }

        $text = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        return $text === false ? null : $text;
    }

    public function load(): ApacheNode
    {
        $this->variables = $this->envvars();
        $this->modules = array_fill_keys(self::STATIC_MODULES, true);
        $top = new ApacheNode('block', 'config', [], "{$this->root}/{$this->main}", 0, 0);
        $this->parseFile("{$this->root}/{$this->main}", $top);

        return $top;
    }

    /**
     * The text of a file the tree came from (for editing in place).
     */
    public static function lines(string $text): array
    {
        return preg_split('/\r?\n/', $text) ?: [];
    }

    private function parseFile(string $file, ApacheNode $into): void
    {
        if (count($this->files) >= self::MAX_FILES) {
            $this->notes[] = 'Stopped after ' . self::MAX_FILES . ' files.';

            return;
        }

        if (in_array($file, $this->files, true)) {
            $this->notes[] = "$file is included twice; read once.";

            return;
        }

        $text = $this->contents($file);

        if ($text === null) {
            $this->notes[] = "Can't read $file.";

            return;
        }

        $this->files[] = $file;
        $this->parseText($text, $file, $into);
    }

    /**
     * Parse one file's text into $into. Public for .htaccess files and tests.
     */
    public function parseText(string $text, string $file, ApacheNode $into): void
    {
        $stack = [$into];
        $lines = self::lines($text);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $start = $i + 1;
            $line = rtrim($lines[$i]);

            while (str_ends_with($line, '\\') && $i + 1 < $count) {
                $line = substr($line, 0, -1) . ' ' . trim($lines[++$i]);
            }

            $line = trim($line);
            $parent = $stack[count($stack) - 1];

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('#^</([A-Za-z]+)\s*>$#', $line, $m) === 1) {
                if (count($stack) > 1 && $parent->name === strtolower($m[1])) {
                    $parent->endLine = $start;
                    array_pop($stack);
                } else {
                    $this->notes[] = "$file:$start: </{$m[1]}> doesn't close anything.";
                }

                continue;
            }

            if (preg_match('#^<([A-Za-z]+)(?:\s+(.*?))?\s*>$#', $line, $m) === 1) {
                $name = strtolower($m[1]);
                $args = $this->arguments($m[2] ?? '');
                $node = new ApacheNode('block', $name, $args, $file, $start, $start, $parent->active && $this->condition($name, $args), $parent);
                $parent->children[] = $node;
                $stack[] = $node;

                continue;
            }

            $words = $this->arguments($line);
            $name = strtolower((string) array_shift($words));
            $on = $parent->active;
            $node = new ApacheNode('directive', $name, $words, $file, $start, $i + 1, $on, $parent);
            $parent->children[] = $node;

            if (!$on) {
                continue;
            }

            match ($name) {
                'define' => isset($words[0]) ? $this->variables[$words[0]] = $words[1] ?? '' : null,
                'undefine' => isset($words[0]) ? $this->forget($words[0]) : null,
                'serverroot' => isset($words[0]) ? $this->serverRoot = rtrim($words[0], '/') : null,
                'loadmodule' => isset($words[0]) ? $this->modules[preg_replace('/_module$/', '', $words[0])] = true : null,
                'include', 'includeoptional' => $this->include($words[0] ?? '', $name === 'includeoptional', $node, $file, $start),
                default => null,
            };
        }

        if (count($stack) > 1) {
            $this->notes[] = "$file: <{$stack[count($stack) - 1]->name}> is never closed.";
        }
    }

    private function forget(string $name): void
    {
        unset($this->variables[$name]);
    }

    /**
     * An Include's files go into the including section, where the Include was.
     */
    private function include(string $pattern, bool $optional, ApacheNode $node, string $file, int $line): void
    {
        $parent = $node->parent ?? $node;
        $path = str_starts_with($pattern, '/') ? $pattern : "{$this->serverRoot}/$pattern";

        if (is_dir($path)) {
            $files = $this->filesUnder($path);
        } else {
            $matched = glob($path) ?: [];
            sort($matched, SORT_STRING);
            $files = [];

            foreach ($matched as $match) {
                array_push($files, ...(is_dir($match) ? $this->filesUnder($match) : [$match]));
            }
        }

        if ($files === [] && !$optional && !str_contains($pattern, '*')) {
            $this->notes[] = "$file:$line: Include $pattern matches nothing.";
        }

        foreach ($files as $included) {
            $this->parseFile($included, $parent);
        }
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Whether a conditional section's contents count (other sections always do).
     *
     * @param list<string> $args
     */
    private function condition(string $name, array $args): bool
    {
        $arg = $args[0] ?? '';
        $negate = str_starts_with($arg, '!');
        $arg = ltrim($arg, '!');

        $result = match ($name) {
            'ifmodule' => isset($this->modules[preg_replace(['/^mod_(.+)\.c$/', '/_module$/'], ['$1', ''], $arg)]),
            'ifdefine' => array_key_exists($arg, $this->variables),
            'iffile' => file_exists(str_starts_with($arg, '/') ? $arg : "{$this->serverRoot}/$arg"),
            'ifversion' => $this->versionMatches($args),
            default => true,
        };

        return in_array($name, ApacheNode::CONDITIONALS, true) ? $result !== $negate : true;
    }

    /**
     * @param list<string> $args
     */
    private function versionMatches(array $args): bool
    {
        if ($this->version === null) {
            return true;
        }

        [$operator, $wanted] = count($args) > 1 ? [$args[0], $args[1]] : ['=', $args[0] ?? ''];
        $negate = str_starts_with($operator, '!');
        $operator = ltrim($operator, '!');

        $result = match ($operator) {
            '=', '==' => version_compare($this->version, $wanted, '=='),
            '>' => version_compare($this->version, $wanted, '>'),
            '>=' => version_compare($this->version, $wanted, '>='),
            '<' => version_compare($this->version, $wanted, '<'),
            '<=' => version_compare($this->version, $wanted, '<='),
            '~' => @preg_match('#' . str_replace('#', '\\#', $wanted) . '#', $this->version) === 1,
            default => true,
        };

        return $result !== $negate;
    }

    /**
     * Words of a directive, honouring quotes, with ${VARIABLES} filled in.
     *
     * @return list<string>
     */
    private function arguments(string $text): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $text, $matches, PREG_SET_ORDER);

        return array_map(function (array $m) {
            $word = isset($m[3]) ? $m[3] : (isset($m[2]) && $m[2] !== '' ? $m[2] : str_replace('\\"', '"', $m[1]));

            return (string) preg_replace_callback('/\$\{(\w+)\}/', fn ($v) => $this->variables[$v[1]] ?? (getenv($v[1]) ?: $v[0]), $word);
        }, $matches);
    }

    /**
     * `export NAME=value` lines of Debian's envvars ($SUFFIX-style references resolved, unknown ones empty).
     *
     * @return array<string, string>
     */
    private function envvars(): array
    {
        $text = is_readable("{$this->root}/envvars") ? (string) file_get_contents("{$this->root}/envvars") : '';
        $variables = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*export\s+(\w+)=(.*)$/', $line, $m) === 1) {
                $value = trim(trim((string) preg_replace('/\s+#.*$/', '', $m[2])), '"\'');
                $variables[$m[1]] = (string) preg_replace_callback('/\$\{?(\w+)\}?/', fn ($v) => $variables[$v[1]] ?? '', $value);
            }
        }

        return $variables;
    }
}
