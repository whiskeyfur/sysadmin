<?php

namespace App\Services;

use App\DTOs\ApacheNode;

/**
 * The mod_rewrite directives of one scope (a <VirtualHost>, a <Directory>,
 * a .htaccess file, or the main server), as rules: each RewriteRule with the
 * RewriteCond lines before it, parsed (pattern, substitution, flags) and
 * with their lines, so one rule can be changed, removed, added or moved in
 * the file's text while everything else stays as it was.
 */
class RewriteRules
{
    /**
     * Flags a rule can have, with what they do (for the editor). Those with
     * a value take "=value".
     *
     * @var array<string, string>
     */
    public const RULE_FLAGS = [
        'L' => 'Last: stop here',
        'END' => 'End: stop, and no rewriting of the result either',
        'R' => 'Redirect (302, or R=301 etc.)',
        'NC' => 'No case: match case-insensitively',
        'QSA' => 'Append the original query string',
        'QSD' => 'Discard the original query string',
        'NE' => 'No escaping of the result',
        'PT' => 'Pass through to Alias etc.',
        'F' => 'Forbidden (403)',
        'G' => 'Gone (410)',
        'P' => 'Proxy (needs mod_proxy)',
        'C' => 'Chain: the next rule only if this one matched',
        'S' => 'Skip the next N rules (S=N)',
        'N' => 'Next: start the rules again',
        'E' => 'Set an environment variable (E=VAR:value)',
        'T' => 'MIME type (T=type)',
        'H' => 'Handler (H=handler)',
        'B' => 'Escape backreferences',
        'DPI' => 'Discard path info',
        'NS' => 'Not for sub-requests',
        'CO' => 'Set a cookie (CO=...)',
    ];

    public const COND_FLAGS = ['NC' => 'No case', 'OR' => 'Or the next condition', 'NV' => 'No vary'];

    /**
     * @var list<array{conds: list<array{test: string, pattern: string, flags: array<string, true>, node: ApacheNode}>,
     *                 pattern: string, substitution: string, flags: list<array{0: string, 1: ?string}>, node: ApacheNode,
     *                 start: int, end: int}>
     */
    public array $rules = [];

    public ?bool $engine = null;

    public ?ApacheNode $engineNode = null;

    public ?string $base = null;

    public ?ApacheNode $baseNode = null;

    /**
     * @var list<string> RewriteOptions values (e.g. Inherit)
     */
    public array $options = [];

    /**
     * @var list<string> directives that couldn't be used (e.g. conditions with no rule after them)
     */
    public array $problems = [];

    /**
     * Any mod_rewrite directive at all (such a scope replaces its parent's rules; see ApacheSimulator).
     */
    public bool $present = false;

    /**
     * @param list<ApacheNode> $directives the scope's own directives, conditional sections looked through
     * @param bool $raw values as written in the file (for editing), not with ${VARIABLES} filled in (for simulating)
     */
    public static function fromNodes(array $directives, bool $raw = false): self
    {
        $set = new self();
        $arg = fn (ApacheNode $node, int $i = 0) => $raw ? $node->rawArg($i) : $node->arg($i);
        $pending = [];

        foreach ($directives as $node) {
            if ($node->kind !== 'directive' || !str_starts_with($node->name, 'rewrite')) {
                continue;
            }

            $set->present = true;

            switch ($node->name) {
                case 'rewriteengine':
                    $set->engine = strtolower($node->arg()) === 'on';
                    $set->engineNode = $node;

                    break;

                case 'rewritebase':
                    $set->base = $arg($node);
                    $set->baseNode = $node;

                    break;

                case 'rewriteoptions':
                    array_push($set->options, ...$node->args);

                    break;

                case 'rewritecond':
                    if (count($node->args) < 2) {
                        $set->problems[] = $node->where() . ': RewriteCond needs a test string and a pattern.';

                        break;
                    }

                    $pending[] = ['test' => $arg($node, 0), 'pattern' => $arg($node, 1), 'flags' => self::condFlags($arg($node, 2)), 'node' => $node];

                    break;

                case 'rewriterule':
                    if (count($node->args) < 2) {
                        $set->problems[] = $node->where() . ': RewriteRule needs a pattern and a substitution.';
                        $pending = [];

                        break;
                    }

                    $set->rules[] = [
                        'conds' => $pending,
                        'pattern' => $arg($node, 0),
                        'substitution' => $arg($node, 1),
                        'flags' => self::ruleFlags($arg($node, 2)),
                        'node' => $node,
                        'start' => $pending === [] ? $node->line : $pending[0]['node']->line,
                        'end' => $node->endLine,
                    ];
                    $pending = [];

                    break;

                default:
                    // RewriteMap, RewriteLock, ...: server-wide, not edited here.
                    break;
            }
        }

        foreach ($pending as $cond) {
            $set->problems[] = $cond['node']->where() . ': RewriteCond without a RewriteRule after it (ignored by Apache).';
        }

        return $set;
    }

    /**
     * @return array<string, true>
     */
    public static function condFlags(string $text): array
    {
        $flags = [];

        foreach (self::flagList($text) as [$name]) {
            $flags[$name === 'NOCASE' ? 'NC' : ($name === 'ORNEXT' ? 'OR' : $name)] = true;
        }

        return $flags;
    }

    /**
     * "[R=301,L]" as [["R", "301"], ["L", null]]; long names (last, redirect, nocase...) as their short ones.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public static function ruleFlags(string $text): array
    {
        $long = ['LAST' => 'L', 'REDIRECT' => 'R', 'NOCASE' => 'NC', 'QSAPPEND' => 'QSA', 'QSDISCARD' => 'QSD', 'NOESCAPE' => 'NE', 'PASSTHROUGH' => 'PT',
            'FORBIDDEN' => 'F', 'GONE' => 'G', 'PROXY' => 'P', 'CHAIN' => 'C', 'SKIP' => 'S', 'NEXT' => 'N', 'ENV' => 'E', 'TYPE' => 'T', 'HANDLER' => 'H',
            'NOSUBREQ' => 'NS', 'COOKIE' => 'CO', 'DISCARDPATH' => 'DPI'];

        return array_map(fn ($flag) => [$long[$flag[0]] ?? $flag[0], $flag[1]], self::flagList($text));
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private static function flagList(string $text): array
    {
        $text = trim($text);

        if ($text === '' || !str_starts_with($text, '[') || !str_ends_with($text, ']')) {
            return [];
        }

        $flags = [];

        foreach (explode(',', substr($text, 1, -1)) as $flag) {
            $flag = trim($flag);

            if ($flag === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $flag, 2), 2, null);
            $flags[] = [strtoupper(trim($name)), $value === null ? null : trim($value)];
        }

        return $flags;
    }

    /**
     * A rule as the lines to write (with the given indentation).
     *
     * @param array{conds: list<array{test: string, pattern: string, flags: array<string, true>}>, pattern: string,
     *              substitution: string, flags: list<array{0: string, 1: ?string}>} $rule
     * @return list<string>
     */
    public static function lines(array $rule, string $indent = ''): array
    {
        $lines = [];

        foreach ($rule['conds'] as $cond) {
            $flags = array_keys($cond['flags']);
            $lines[] = $indent . 'RewriteCond ' . self::quote($cond['test']) . ' ' . self::quote($cond['pattern']) . ($flags === [] ? '' : ' [' . implode(',', $flags) . ']');
        }

        $flags = array_map(fn ($f) => $f[1] === null ? $f[0] : "{$f[0]}={$f[1]}", $rule['flags']);
        $lines[] = $indent . 'RewriteRule ' . self::quote($rule['pattern']) . ' ' . self::quote($rule['substitution']) . ($flags === [] ? '' : ' [' . implode(',', $flags) . ']');

        return $lines;
    }

    /**
     * Quote a word only when Apache needs it (spaces, quotes, or empty).
     */
    public static function quote(string $word): string
    {
        return $word !== '' && preg_match('/[\s"\']/', $word) !== 1 ? $word : '"' . str_replace('"', '\\"', $word) . '"';
    }

    /**
     * Check a rule before writing it; null if fine.
     *
     * @param array{conds: list<array{test: string, pattern: string, flags: array<string, true>}>, pattern: string,
     *              substitution: string, flags: list<array{0: string, 1: ?string}>} $rule
     */
    public static function problem(array $rule): ?string
    {
        foreach ([$rule['pattern'], $rule['substitution'], ...array_merge(...array_map(fn ($c) => [$c['test'], $c['pattern']], $rule['conds']))] as $text) {
            if (preg_match('/[\x00-\x1f\x7f]/', $text) === 1) {
                return 'Patterns and substitutions are one line of text.';
            }
        }

        if (trim($rule['pattern']) === '' || trim($rule['substitution']) === '') {
            return 'A rule needs a pattern and a substitution ("-" for none).';
        }

        if (!self::regexValid(ltrim($rule['pattern'], '!'))) {
            return "The pattern \"{$rule['pattern']}\" isn't a valid regular expression.";
        }

        foreach ($rule['conds'] as $cond) {
            if (trim($cond['test']) === '' || trim($cond['pattern']) === '') {
                return 'Each condition needs a test string and a pattern.';
            }

            $pattern = ltrim($cond['pattern'], '!');

            if (!ApacheSimulator::isSpecialCondPattern($pattern) && !self::regexValid($pattern)) {
                return "The condition pattern \"{$cond['pattern']}\" isn't a valid regular expression.";
            }
        }

        foreach ($rule['flags'] as [$name, $value]) {
            if (!isset(self::RULE_FLAGS[$name])) {
                return "Unknown flag $name.";
            }

            if ($name === 'R' && $value !== null && !in_array($value, ['permanent', 'temp', 'seeother'], true) && (!ctype_digit($value) || (int) $value < 300 || (int) $value > 399) && !in_array((int) $value, [403, 404, 410], true)) {
                return 'R= takes a 3xx code (or permanent, temp, seeother).';
            }

            if ($value !== null && preg_match('/[\s,\]\[]/', $value) === 1) {
                return "The value of $name can't contain spaces, commas or brackets.";
            }
        }

        return null;
    }

    public static function regexValid(string $pattern): bool
    {
        return @preg_match(ApacheSimulator::delimit($pattern), '') !== false;
    }
}
