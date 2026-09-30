<?php

namespace App\Services;

use App\DTOs\ApacheNode;

/**
 * Where a URL ends up on this machine's Apache, worked out from the
 * configuration (ApacheConfigTree) and the files, without sending a request:
 * which virtual host takes it, what the server-level RewriteRules,
 * Redirect/RedirectMatch and Alias/AliasMatch do, how the <Directory>
 * sections and .htaccess files along the path merge (as Apache 2.4 does:
 * a section with any mod_rewrite directive replaces its parent's rules
 * unless RewriteOptions Inherit; one without leaves them), the per-directory
 * rules (re-running the request after an internal redirect, as Apache does),
 * access (Require all/local/ip), then index files, the trailing-slash
 * redirect, FallbackResource and PATH_INFO. Every step is traced.
 *
 * What it can't know (RewriteMap programs, Require by user or expression,
 * <If> sections, ErrorDocument, mod_proxy's answer) is said in the trace,
 * never guessed. Checked against a real apache2 by
 * tests/checks/ApacheSimulator.test.php.
 */
class ApacheSimulator
{
    public const MAX_ROUNDS = 10;

    public const MAX_NEXT = 100;

    /**
     * @var list<array{phase: string, text: string, where: ?string}>
     */
    private array $steps = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @var array<string, string> variables set by E= flags
     */
    private array $env = [];

    public function __construct(private readonly ApacheNode $top, private readonly ApacheConfigTree $tree)
    {
    }

    /**
     * @param array{method?: string, headers?: array<string, string>, remote_addr?: string} $request
     * @return array<string, mixed> status, kind (file, redirect, forbidden, gone, not_found, listing, proxy, error), location,
     *                              file, path_info, handler, steps (phase, text, where), warnings
     */
    public function simulate(string $url, array $request = []): array
    {
        $this->steps = [];
        $this->warnings = [];
        $this->env = [];

        $parts = parse_url(preg_match('#^[a-z]+://#i', $url) === 1 ? $url : "http://$url");

        if ($parts === false || empty($parts['host'])) {
            return $this->result(400, 'error', text: "That isn't a URL.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $rawPath = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        $request += ['method' => 'GET', 'headers' => [], 'remote_addr' => '127.0.0.1'];

        if (stripos($rawPath, '%2f') !== false) {
            $this->step('request', 'The path has an encoded slash (%2F): with AllowEncodedSlashes Off (the default) Apache answers 404 straight away.');

            return $this->result(404, 'not_found');
        }

        $vhost = $this->selectVhost($host, $port);
        $main = $this->serverDirectives();
        $own = $vhost?->effective() ?? $main;
        $context = [
            'scheme' => $scheme, 'host' => $host, 'port' => $port, 'method' => strtoupper($request['method']),
            'headers' => array_change_key_case($request['headers']), 'remote_addr' => $request['remote_addr'],
            'server_name' => $this->first($own, 'servername') ?? $this->first($main, 'servername') ?? $host,
            'document_root' => rtrim($this->first($own, 'documentroot') ?? $this->first($main, 'documentroot') ?? '/var/www/html', '/'),
            'original' => $rawPath . ($query === '' ? '' : "?$query"),
        ];
        $this->step('host', $vhost === null
            ? "No <VirtualHost> for port $port: the main server answers."
            : "Takes $host:$port" . ($context['server_name'] !== $host ? " (ServerName {$context['server_name']})" : '') . '.', $vhost);
        $root = $this->lastNode($own, 'documentroot') ?? $this->lastNode($main, 'documentroot');
        $this->step('host', $root === null ? "No DocumentRoot: Apache's default, {$context['document_root']}." : "Files come from {$context['document_root']}.", $root);

        $uri = $this->decode($rawPath);
        $ended = false;

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            if ($round > 1) {
                $this->step('internal', "Internal redirect to $uri" . ($query === '' ? '' : "?$query") . ': the request starts over.');
            }

            // 1. Server-level mod_rewrite.
            $filename = null;
            $passThrough = false;

            if (!$ended) {
                $server = $this->serverRules($vhost, $main);

                if ($server !== null) {
                    $outcome = $this->runRules($server['rules'], $uri, $uri, $query, $context, null, null, 'server');

                    if (isset($outcome['result'])) {
                        return $outcome['result'];
                    }

                    if ($outcome['changed']) {
                        $query = $outcome['query'];
                        $ended = $outcome['end'];
                        $target = $outcome['target'];
                        $passThrough = $outcome['pt'];

                        if (!$passThrough && ($first = explode('/', ltrim($target, '/'))[0]) !== '' && file_exists('/' . $first)) {
                            $filename = $target;
                            $this->step('rewrite', "$target is a filesystem path (/$first exists): used as the file.");
                        } elseif (!$passThrough) {
                            $filename = $context['document_root'] . $target;
                            $this->step('rewrite', "Mapped under DocumentRoot: $filename (Alias and Redirect are skipped; PT would pass it on).");
                        } else {
                            $uri = $target;
                            $this->step('rewrite', "PT: $target goes on to Alias and DocumentRoot.");
                        }
                    }
                }
            }

            // 2. mod_alias: Redirect(Match), then Alias(Match).
            if ($filename === null) {
                $redirect = $this->aliasRedirect($vhost, $main, $uri, $query, $context);

                if ($redirect !== null) {
                    return $redirect;
                }

                $filename = $this->alias($vhost, $main, $uri) ?? $context['document_root'] . $uri;

                if (!str_starts_with($filename, $context['document_root'] . '/') && $filename !== $context['document_root']) {
                    // Traced by alias().
                } else {
                    $this->step('map', "$uri → $filename");
                }
            }

            // 3. Directory walk: <Directory>, .htaccess, <DirectoryMatch>, <Files>, <Location>.
            [$file, $pathInfo] = $this->splitPathInfo($filename);
            $config = $this->walk($file, $uri, $vhost, $main, $context);

            if (isset($config['result'])) {
                return $config['result'];
            }

            // 4. Access.
            $denied = $this->access($config, $context);

            if ($denied !== null) {
                return $denied;
            }

            // 5. Per-directory mod_rewrite.
            $perDir = $config['rewrite'];

            if (!$ended && $perDir !== null && $perDir['engine'] === true && $perDir['rules'] !== []) {
                $dir = rtrim($perDir['dir'], '/') . '/';
                $full = $file . $pathInfo;
                $relative = str_starts_with($full, $dir) ? substr($full, strlen($dir)) : ltrim($full, '/');
                $this->step('rewrite', "Per-directory rules of $dir (" . $perDir['where'] . ") see \"$relative\".");
                $outcome = $this->runRules($perDir['rules'], $relative, $uri, $query, $context + ['request_filename' => $file], $dir, $perDir['base'], 'directory');

                if (isset($outcome['result'])) {
                    return $outcome['result'];
                }

                if ($outcome['changed']) {
                    if (!isset($config['options']['followsymlinks']) && !isset($config['options']['symlinksifownermatch'])) {
                        $this->step('rewrite', 'Options FollowSymLinks (or SymLinksIfOwnerMatch) is off here, so Apache refuses per-directory rewrites.');

                        return $this->result(403, 'forbidden');
                    }

                    $ended = $outcome['end'];
                    $target = $outcome['target'];
                    $newQuery = $outcome['query'];

                    if (!str_starts_with($target, '/')) {
                        if ($perDir['base'] !== null) {
                            $target = rtrim($perDir['base'], '/') . '/' . $target;
                        } else {
                            $target = $dir . $target;

                            if (str_starts_with($target, $context['document_root'] . '/')) {
                                $target = substr($target, strlen($context['document_root']));
                            } else {
                                $this->warnings[] = "The rules in $dir rewrite to a relative path outside the DocumentRoot without RewriteBase: Apache uses the filesystem path as the URL, which rarely works.";
                            }
                        }
                    }

                    if ($target === $uri && $newQuery === $query) {
                        $this->step('rewrite', 'Rewritten to the same URL: Apache ignores it.');
                    } else {
                        $uri = $target;
                        $query = $newQuery;

                        continue;
                    }
                }
            }

            // 6. Per-directory Redirect(Match) (mod_alias in <Directory>/.htaccess).
            foreach ($config['redirects'] as $redirect) {
                $hit = $this->redirectMatch($redirect, $uri, $query, $context);

                if ($hit !== null) {
                    return $hit;
                }
            }

            // 7. The file.
            if (is_dir($file)) {
                if (!str_ends_with($uri, '/') && $config['directory_slash']) {
                    $location = $this->absolute($uri . '/', $query, $context);
                    $this->step('dir', "$file is a directory: mod_dir redirects to add the slash.", $config['at']['directoryslash'] ?? null);

                    return $this->result(301, 'redirect', location: $location);
                }

                foreach ($config['directory_index'] as $index) {
                    if ($index === 'disabled') {
                        break;
                    }

                    $candidate = str_starts_with($index, '/') ? $index : rtrim($uri, '/') . '/' . $index;

                    if (str_starts_with($index, '/') || is_file(rtrim($file, '/') . '/' . $index)) {
                        $this->step('dir', "Index file: $index.", $config['at']['directoryindex'] ?? null, isset($config['at']['directoryindex']) ? null : 'DirectoryIndex index.html (default)');
                        $uri = $candidate;

                        continue 2;
                    }
                }

                if (isset($config['options']['indexes'])) {
                    $this->step('dir', 'No index file; Options Indexes lists the directory.', $config['at']['options'] ?? null);

                    return $this->result(200, 'listing', file: $file);
                }

                $this->step('dir', 'No index file and no Options Indexes: forbidden.', $config['at']['options'] ?? null);

                return $this->result(403, 'forbidden', file: $file);
            }

            if (is_file($file)) {
                $handler = $config['handler'];

                if ($pathInfo !== '' && $handler === null && $config['accept_path_info'] !== true) {
                    $this->step('file', "$file with extra path $pathInfo: the default handler refuses path info (AcceptPathInfo).", $config['at']['acceptpathinfo'] ?? null);

                    return $this->result(404, 'not_found', file: $file);
                }

                $this->step('file', "Serves $file" . ($pathInfo !== '' ? " with PATH_INFO $pathInfo" : '') . ($handler !== null ? " through $handler" : '') . '.', $handler !== null ? ($config['at']['sethandler'] ?? null) : null);

                return $this->result(200, 'file', file: $file, pathInfo: $pathInfo === '' ? null : $pathInfo, handler: $handler);
            }

            if ($config['handler'] !== null && !self::needsFile($config['handler'])) {
                $this->step('file', "{$config['handler']} answers; no file needed.", $config['at']['sethandler'] ?? null);

                return $this->result(200, 'handler', handler: $config['handler']);
            }

            if ($config['fallback'] !== null && $config['fallback'] !== 'disabled') {
                $this->step('file', "$file doesn't exist: falls back to {$config['fallback']}.", $config['at']['fallbackresource'] ?? null);
                $uri = $config['fallback'];

                continue;
            }

            $this->step('file', "$file doesn't exist.");

            return $this->result(404, 'not_found', file: $file);
        }

        $this->step('internal', 'More than ' . self::MAX_ROUNDS . ' internal redirects: Apache stops with 500 (LimitInternalRecursion); the rules loop.');

        return $this->result(500, 'error');
    }

    /**
     * Handlers that serve a file (PHP and CGI); others (server-status, server-info...) answer by themselves.
     */
    public static function needsFile(string $handler): bool
    {
        return preg_match('/php|cgi-script|proxy:/i', $handler) === 1;
    }

    /**
     * RewriteCond patterns that aren't regular expressions: -d, -f, <x, =x, -eq 3...
     */
    public static function isSpecialCondPattern(string $pattern): bool
    {
        return preg_match('/^(-[dfFhlLsUx]|-(eq|ge|gt|le|lt|ne)\s|[<>=])/', $pattern) === 1;
    }

    /**
     * An Apache (PCRE) pattern as a PHP one.
     */
    public static function delimit(string $pattern, bool $nocase = false): string
    {
        return "\x01" . $pattern . "\x01" . ($nocase ? 'i' : '');
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $context
     * @return array{changed: bool, target: string, query: string, end: bool, pt: bool, result?: array<string, mixed>}
     */
    private function runRules(array $rules, string $input, string $uri, string $query, array $context, ?string $dir, ?string $base, string $where): array
    {
        $current = $input;
        $currentQuery = $query;
        $changed = false;
        $end = false;
        $pt = false;
        $skipChain = false;
        $next = 0;
        $count = count($rules);

        for ($i = 0; $i < $count; $i++) {
            $rule = $rules[$i];
            $flags = [];

            foreach ($rule['flags'] as [$name, $value]) {
                $flags[$name] = $value ?? true;
            }

            $at = $rule['node'];
            $label = $at->where() . ': RewriteRule ' . $rule['pattern'] . ' ' . $rule['substitution'] . ($rule['flags'] === [] ? '' : ' [' . implode(',', array_map(fn ($f) => $f[1] === null ? $f[0] : "{$f[0]}={$f[1]}", $rule['flags'])) . ']');

            if ($skipChain) {
                $skipChain = isset($flags['C']);
                $this->step('rewrite', "Skipped (chained to a rule that didn't match).", $at);

                continue;
            }

            $negated = str_starts_with($rule['pattern'], '!');
            $matched = @preg_match(self::delimit(ltrim($rule['pattern'], '!'), isset($flags['NC'])), $current, $captures);

            if ($matched === false) {
                $this->warnings[] = "$label: invalid pattern.";

                continue;
            }

            if (($matched === 1) === $negated) {
                $this->step('rewrite', "No match for \"$current\".", $at);
                $skipChain = isset($flags['C']);

                continue;
            }

            $captures = $negated ? [] : $captures;
            $condCaptures = [];

            $matches = "Matches \"$current\"" . ($captures !== [] && count($captures) > 1 ? ' ($1=' . ($captures[1] ?? '') . (count($captures) > 2 ? ', $2=' . $captures[2] : '') . ')' : '');

            if ($rule['conds'] === []) {
                $this->step('rewrite', "$matches.", $at);
            } else {
                $this->step('rewrite', "$matches; its conditions:", $at);

                if (!$this->conditions($rule['conds'], $captures, $condCaptures, $context + ['uri' => $uri, 'query' => $currentQuery])) {
                    $this->step('rewrite', "The conditions don't hold: the rule doesn't apply.", $at);
                    $skipChain = isset($flags['C']);

                    continue;
                }

                $this->step('rewrite', 'The conditions hold: the rule applies.', $at);
            }

            foreach ($flags as $name => $value) {
                if ($name === 'E' && is_string($value) && str_contains($value, ':')) {
                    [$var, $val] = explode(':', $value, 2);
                    $this->env[ltrim($var, '!')] = $this->expand($val, $captures, $condCaptures, $context + ['uri' => $uri, 'query' => $currentQuery]);
                }
            }

            if (isset($flags['F'])) {
                $this->step('rewrite', 'F: forbidden.', $at);

                return ['changed' => false, 'target' => $current, 'query' => $currentQuery, 'end' => true, 'pt' => false, 'result' => $this->result(403, 'forbidden')];
            }

            if (isset($flags['G'])) {
                $this->step('rewrite', 'G: gone.', $at);

                return ['changed' => false, 'target' => $current, 'query' => $currentQuery, 'end' => true, 'pt' => false, 'result' => $this->result(410, 'gone')];
            }

            if ($rule['substitution'] !== '-') {
                $substituted = $this->expand($rule['substitution'], isset($flags['B']) ? array_map('rawurlencode', $captures) : $captures, $condCaptures, $context + ['uri' => $uri, 'query' => $currentQuery]);
                [$path, $newQuery] = $this->splitQuery($substituted, $currentQuery, isset($flags['QSA']), isset($flags['QSD']));
                $absolute = preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1;

                if (isset($flags['P'])) {
                    $this->warnings[] = 'The request is proxied: what the other server answers can\'t be simulated.';

                    return ['changed' => false, 'target' => $path, 'query' => $newQuery, 'end' => true, 'pt' => false, 'result' => $this->result(200, 'proxy', location: $path . ($newQuery === '' ? '' : "?$newQuery"))];
                }

                if (isset($flags['R']) || $absolute) {
                    $code = $this->redirectCode($flags['R'] ?? true);

                    if ($absolute) {
                        $location = $path . ($newQuery === '' ? '' : "?$newQuery");
                    } else {
                        if (!str_starts_with($path, '/') && $dir !== null) {
                            $path = $base !== null ? rtrim($base, '/') . '/' . $path : (str_starts_with($dir, $context['document_root'] . '/') ? substr($dir, strlen($context['document_root'])) : $dir) . $path;
                        }

                        $location = $this->absolute($path, $newQuery, $context, isset($flags['NE']));
                    }

                    $this->step('rewrite', "Redirect ($code) to $location.", $at);

                    return ['changed' => true, 'target' => $path, 'query' => $newQuery, 'end' => true, 'pt' => false,
                        'result' => $code >= 300 && $code < 400 ? $this->result($code, 'redirect', location: $location) : $this->result($code, $code === 410 ? 'gone' : ($code === 403 ? 'forbidden' : 'not_found'))];
                }

                $this->step('rewrite', "Rewritten to $path" . ($newQuery === '' ? '' : "?$newQuery") . '.', $at);
                $current = $path;
                $currentQuery = $newQuery;
                $changed = true;
            } else {
                $this->step('rewrite', '"-": the URL stays as it is.', $at);
            }

            $pt = $pt || isset($flags['PT']);

            if (isset($flags['END'])) {
                $end = true;

                break;
            }

            if (isset($flags['L'])) {
                break;
            }

            if (isset($flags['N']) && ++$next <= self::MAX_NEXT) {
                $i = -1;

                continue;
            }

            if (isset($flags['S']) && is_string($flags['S']) && ctype_digit($flags['S'])) {
                $i += (int) $flags['S'];
            }
        }

        if (!$changed) {
            $this->step('rewrite', ucfirst($where) . '-level rules leave the URL as it is.');
        }

        return ['changed' => $changed, 'target' => $current, 'query' => $currentQuery, 'end' => $end, 'pt' => $pt];
    }

    /**
     * @param list<array{test: string, pattern: string, flags: array<string, true>, node: ApacheNode}> $conds
     * @param array<int, string> $captures the rule's
     * @param array<int, string> $condCaptures out: the last matching condition's
     * @param array<string, mixed> $context
     */
    private function conditions(array $conds, array $captures, array &$condCaptures, array $context): bool
    {
        // As mod_rewrite's apply_rewrite_rule(): a failing [OR] condition moves on to the next;
        // a passing one skips the rest of its OR group; a failing plain one fails the rule.
        $count = count($conds);

        for ($i = 0; $i < $count; $i++) {
            $cond = $conds[$i];
            $test = $this->expand($cond['test'], $captures, $condCaptures, $context);
            $pattern = $cond['pattern'];
            $negate = str_starts_with($pattern, '!');
            $found = [];
            $result = $this->condition($test, $negate ? substr($pattern, 1) : $pattern, isset($cond['flags']['NC']), $found) !== $negate;
            $this->step('cond', '"' . $test . '" ' . ($result ? 'holds' : 'fails') . '.', $cond['node']);

            if ($result && !$negate && $found !== []) {
                $condCaptures = array_filter($found, fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY);
            }

            if (isset($cond['flags']['OR'])) {
                if ($result) {
                    while ($i < $count - 1 && isset($conds[$i]['flags']['OR'])) {
                        $i++;
                    }
                }

                continue;
            }

            if (!$result) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, string> $found
     * @param-out array<int|string, string> $found
     */
    private function condition(string $test, string $pattern, bool $nocase, array &$found): bool
    {
        if (preg_match('/^-(d|f|F|s|l|L|h|U|x|e)$/', $pattern, $m) === 1) {
            return match ($m[1]) {
                'd' => is_dir($test),
                'f', 'F' => is_file($test),
                's' => is_file($test) && filesize($test) > 0,
                'l', 'L', 'h' => is_link($test),
                'x' => is_file($test) && is_executable($test),
                'U' => file_exists($test),
                default => file_exists($test),
            };
        }

        if (preg_match('/^-(eq|ge|gt|le|lt|ne)\s*(.*)$/', $pattern, $m) === 1) {
            [$a, $b] = [(int) $test, (int) $m[2]];

            return match ($m[1]) {
                'eq' => $a === $b, 'ge' => $a >= $b, 'gt' => $a > $b, 'le' => $a <= $b, 'lt' => $a < $b, default => $a !== $b
            };
        }

        if (preg_match('/^(<=|>=|<|>|=)(.*)$/s', $pattern, $m) === 1) {
            [$a, $b] = $nocase ? [strtolower($test), strtolower($m[2])] : [$test, $m[2]];
            $b = $m[1] === '=' && $b === '""' ? '' : $b;

            return match ($m[1]) {
                '<' => strcmp($a, $b) < 0, '>' => strcmp($a, $b) > 0, '<=' => strcmp($a, $b) <= 0, '>=' => strcmp($a, $b) >= 0, default => $a === $b
            };
        }

        $matched = @preg_match(self::delimit($pattern, $nocase), $test, $captures);

        if ($matched === false) {
            $this->warnings[] = "Invalid condition pattern $pattern.";

            return false;
        }

        $found = $captures;

        return $matched === 1;
    }

    /**
     * $N, %N and %{VARIABLE} in a test string or substitution.
     *
     * @param array<int, string> $captures
     * @param array<int, string> $condCaptures
     * @param array<string, mixed> $context
     */
    private function expand(string $text, array $captures, array $condCaptures, array $context): string
    {
        $text = (string) preg_replace_callback('/\$\{([^}]*)\}/', function ($m) {
            $this->warnings[] = "RewriteMap lookup \${{$m[1]}} can't be simulated; left empty.";

            return '';
        }, $text);
        $text = (string) preg_replace_callback('/%\{([^}]+)\}/', fn ($m) => $this->variable($m[1], $context), $text);
        $text = (string) preg_replace_callback('/\$(\d)/', fn ($m) => $captures[(int) $m[1]] ?? '', $text);

        return (string) preg_replace_callback('/%(\d)/', fn ($m) => $condCaptures[(int) $m[1]] ?? '', $text);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function variable(string $name, array $context): string
    {
        $upper = strtoupper($name);
        $headers = $context['headers'];

        if (str_starts_with($upper, 'HTTP:')) {
            return (string) ($headers[strtolower(substr($name, 5))] ?? '');
        }

        if (str_starts_with($upper, 'ENV:')) {
            return $this->env[substr($name, 4)] ?? (string) (getenv(substr($name, 4)) ?: '');
        }

        if (str_starts_with($upper, 'SSL:')) {
            $this->warnings[] = "%{{$name}} (TLS details) can't be simulated; empty.";

            return '';
        }

        $path = $context['uri'];
        $https = $context['scheme'] === 'https';

        return match ($upper) {
            'HTTP_HOST' => (string) ($headers['host'] ?? ($context['host'] . (in_array($context['port'], [80, 443], true) ? '' : ':' . $context['port']))),
            'HTTP_USER_AGENT', 'HTTP_REFERER', 'HTTP_COOKIE', 'HTTP_ACCEPT', 'HTTP_FORWARDED', 'HTTP_PROXY_CONNECTION' => (string) ($headers[strtolower(str_replace('_', '-', substr($upper, 5)))] ?? ''),
            'REQUEST_URI' => $path,
            'REQUEST_FILENAME', 'SCRIPT_FILENAME' => (string) ($context['request_filename'] ?? $path),
            'QUERY_STRING' => (string) $context['query'],
            'HTTPS' => $https ? 'on' : 'off',
            'REQUEST_SCHEME' => $context['scheme'],
            'SERVER_PORT' => (string) $context['port'],
            'SERVER_NAME' => (string) $context['server_name'],
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => 'Apache',
            'DOCUMENT_ROOT' => (string) $context['document_root'],
            'REQUEST_METHOD' => (string) $context['method'],
            'THE_REQUEST' => $context['method'] . ' ' . $context['original'] . ' HTTP/1.1',
            'REMOTE_ADDR' => (string) $context['remote_addr'],
            'REMOTE_HOST' => (string) $context['remote_addr'],
            'IPV6' => str_contains((string) $context['remote_addr'], ':') ? 'on' : 'off',
            'TIME_YEAR' => date('Y'), 'TIME_MON' => date('m'), 'TIME_DAY' => date('d'), 'TIME_HOUR' => date('H'), 'TIME_MIN' => date('i'), 'TIME_SEC' => date('s'),
            'TIME_WDAY' => date('w'), 'TIME' => date('YmdHis'),
            'API_VERSION', 'IS_SUBREQ' => $upper === 'IS_SUBREQ' ? 'false' : '',
            default => $this->unknownVariable($name),
        };
    }

    private function unknownVariable(string $name): string
    {
        $this->warnings[] = "%{{$name}} isn't known to the simulator; taken as empty.";

        return '';
    }

    /**
     * @return array{0: string, 1: string} path and the query string after it
     */
    private function splitQuery(string $substituted, string $query, bool $append, bool $discard): array
    {
        if (!str_contains($substituted, '?')) {
            return [$substituted, $discard ? '' : $query];
        }

        [$path, $new] = explode('?', $substituted, 2);

        if ($append && $query !== '' && !$discard) {
            $new = $new === '' ? $query : "$new&$query";
        }

        return [$path, $new];
    }

    private function redirectCode(mixed $value): int
    {
        return match (true) {
            $value === true, $value === 'temp' => 302,
            $value === 'permanent' => 301,
            $value === 'seeother' => 303,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => 302,
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function absolute(string $path, string $query, array $context, bool $noEscape = false): string
    {
        $default = $context['scheme'] === 'https' ? 443 : 80;
        $path = $noEscape ? $path : (string) preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/', fn ($m) => rawurlencode($m[0]), $path);

        return $context['scheme'] . '://' . $context['host'] . ($context['port'] === $default ? '' : ':' . $context['port']) . $path . ($query === '' ? '' : "?$query");
    }

    private function decode(string $path): string
    {
        $decoded = rawurldecode($path);
        $parts = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.') {
                $parts[] = $segment;
            }
        }

        $normal = implode('/', $parts);

        return str_starts_with($normal, '/') ? $normal : "/$normal";
    }

    /**
     * Name-based virtual hosts: those for the port, the one whose ServerName/ServerAlias
     * matches, else the first for the port.
     */
    private function selectVhost(string $host, int $port): ?ApacheNode
    {
        $candidates = [];

        foreach ($this->sections($this->top, ['virtualhost']) as $vhost) {
            foreach ($vhost->args as $address) {
                $vport = preg_match('/:(\d+|\*)$/', $address, $m) === 1 ? $m[1] : '*';

                if ($vport === '*' || (int) $vport === $port) {
                    $candidates[] = $vhost;

                    break;
                }
            }
        }

        foreach ($candidates as $vhost) {
            $names = [];

            foreach ($vhost->effective() as $node) {
                if (in_array($node->name, ['servername', 'serveralias'], true)) {
                    foreach ($node->args as $name) {
                        $names[] = strtolower((string) preg_replace(['#^[a-z]+://#i', '#:\d+$#'], '', $name));
                    }
                }
            }

            foreach ($names as $name) {
                if (fnmatch($name, $host)) {
                    return $vhost;
                }
            }
        }

        if ($candidates !== []) {
            $this->step('host', "No ServerName/ServerAlias matches $host: the first virtual host for port $port is the default.");
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param list<string> $names
     * @return list<ApacheNode>
     */
    private function sections(ApacheNode $in, array $names): array
    {
        return array_values(array_filter($in->effective(), fn (ApacheNode $n) => $n->isBlock(...$names)));
    }

    /**
     * @return list<ApacheNode> the main server's own directives (not those of virtual hosts)
     */
    private function serverDirectives(): array
    {
        return array_values(array_filter($this->top->effective(), fn (ApacheNode $n) => !$n->isBlock('virtualhost')));
    }

    /**
     * @param list<ApacheNode> $nodes
     */
    private function lastNode(array $nodes, string $name): ?ApacheNode
    {
        $found = null;

        foreach ($nodes as $node) {
            if ($node->kind === 'directive' && $node->name === $name) {
                $found = $node;
            }
        }

        return $found;
    }

    /**
     * @param list<ApacheNode> $nodes
     */
    private function first(array $nodes, string $name): ?string
    {
        $value = null;

        foreach ($nodes as $node) {
            if ($node->kind === 'directive' && $node->name === $name) {
                $value = $node->arg();
            }
        }

        return $value;
    }

    /**
     * The server-level rules: the virtual host's (with the main server's after or before them for
     * RewriteOptions Inherit/InheritBefore), or the main server's. RewriteEngine carries over from
     * the main server if the virtual host doesn't set it; the rules don't.
     *
     * @param list<ApacheNode> $main
     * @return array{rules: list<array<string, mixed>>}|null
     */
    private function serverRules(?ApacheNode $vhost, array $main): ?array
    {
        $mainSet = RewriteRules::fromNodes($main);
        $set = $vhost === null ? $mainSet : RewriteRules::fromNodes($vhost->effective());
        $engine = $set->engine ?? $mainSet->engine ?? false;
        $rules = $set->rules;
        $options = array_map('strtolower', $set->options);

        if ($vhost !== null && in_array('inherit', $options, true)) {
            $rules = [...$rules, ...$mainSet->rules];
        } elseif ($vhost !== null && in_array('inheritbefore', $options, true)) {
            $rules = [...$mainSet->rules, ...$rules];
        }

        if (!$engine || $rules === []) {
            if ($rules !== [] && !$engine) {
                $this->step('rewrite', 'Server-level RewriteRules are there, but RewriteEngine is off.');
            }

            return null;
        }

        return ['rules' => $rules];
    }

    /**
     * Redirect / RedirectMatch at server level (virtual host's first, then the main server's).
     *
     * @param list<ApacheNode> $main
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function aliasRedirect(?ApacheNode $vhost, array $main, string $uri, string $query, array $context): ?array
    {
        foreach ([...($vhost?->effective() ?? []), ...($vhost !== null ? $main : [])] as $node) {
            if ($node->kind === 'directive' && in_array($node->name, ['redirect', 'redirectmatch', 'redirectpermanent', 'redirecttemp'], true)) {
                $hit = $this->redirectMatch($node, $uri, $query, $context);

                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function redirectMatch(ApacheNode $node, string $uri, string $query, array $context): ?array
    {
        $args = $node->args;
        $status = match ($node->name) {
            'redirectpermanent' => 'permanent', 'redirecttemp' => 'temp', default => null
        };

        if ($status === null && isset($args[0]) && (in_array(strtolower($args[0]), ['permanent', 'temp', 'seeother', 'gone'], true) || ctype_digit($args[0]))) {
            $status = strtolower((string) array_shift($args));
        }

        $code = match ($status) {
            null, 'temp' => 302, 'permanent' => 301, 'seeother' => 303, 'gone' => 410, default => (int) $status
        };
        [$from, $to] = [$args[0] ?? '', $code >= 300 && $code <= 399 ? ($args[1] ?? null) : null];

        if ($node->name === 'redirectmatch') {
            if (@preg_match(self::delimit($from), $uri, $m) !== 1) {
                return null;
            }

            $target = $to === null ? null : (string) preg_replace_callback('/\$(\d)/', fn ($x) => $m[(int) $x[1]] ?? '', $to);
        } else {
            $prefix = rtrim($from, '/');

            if ($uri !== $from && $uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
                return null;
            }

            $target = $to === null ? null : rtrim($to, '/') . substr($uri, strlen($prefix)) . (str_ends_with($to, '/') && substr($uri, strlen($prefix)) === '' ? '/' : '');
        }

        if ($code < 300 || $code > 399 || $target === null) {
            $this->step('alias', "Matches: answers $code.", $node);

            return $this->result($code, match ($code) {
                410 => 'gone', 403 => 'forbidden', 404 => 'not_found', default => 'error'
            });
        }

        [$targetPath, $targetQuery] = array_pad(explode('?', $target, 2), 2, null);
        $location = preg_match('#^[a-z]+://#i', $target) === 1 ? $target : $this->absolute($targetPath, (string) $targetQuery, $context);
        // mod_alias keeps the request's query string unless the target has its own.
        $location .= $query !== '' && $targetQuery === null ? "?$query" : '';
        $this->step('alias', "Matches: redirect ($code) to $location.", $node);

        return $this->result($code, 'redirect', location: $location);
    }

    /**
     * Alias / AliasMatch / ScriptAlias(Match): the file, or null for DocumentRoot.
     *
     * @param list<ApacheNode> $main
     */
    private function alias(?ApacheNode $vhost, array $main, string $uri): ?string
    {
        foreach ([...($vhost?->effective() ?? []), ...($vhost !== null ? $main : [])] as $node) {
            if ($node->kind !== 'directive' || !in_array($node->name, ['alias', 'aliasmatch', 'scriptalias', 'scriptaliasmatch'], true) || count($node->args) < 2) {
                continue;
            }

            [$from, $to] = $node->args;

            if (str_ends_with($node->name, 'match')) {
                if (@preg_match(self::delimit($from), $uri, $m) === 1) {
                    $file = (string) preg_replace_callback('/\$(\d)/', fn ($x) => $m[(int) $x[1]] ?? '', $to);
                    $this->step('alias', "Matches: the file is $file.", $node);

                    return $file;
                }

                continue;
            }

            // Alias /icons/ needs the slash; Alias /icons matches /icons and /icons/...
            if ($uri === $from || (str_ends_with($from, '/') ? str_starts_with($uri, $from) : str_starts_with($uri, $from . '/'))) {
                $file = rtrim($to, '/') . substr($uri, strlen(rtrim($from, '/')));
                $this->step('alias', "Matches: the file is $file.", $node);

                return $file;
            }
        }

        return null;
    }

    /**
     * The file and any path after it (PATH_INFO), as Apache's directory walk leaves them: the deepest
     * existing file; or, when the path doesn't exist, the first missing segment under the deepest
     * existing directory (so <Files> sections see that name, e.g. ".git" in /.git/config).
     *
     * @return array{0: string, 1: string}
     */
    private function splitPathInfo(string $filename): array
    {
        if (file_exists($filename)) {
            return [$filename, ''];
        }

        $segments = array_values(array_filter(explode('/', $filename), fn (string $s) => $s !== ''));
        $path = '';

        foreach ($segments as $i => $segment) {
            $next = "$path/$segment";

            if (is_file($next)) {
                return [$next, '/' . implode('/', array_slice($segments, $i + 1))];
            }

            if (!is_dir($next)) {
                $rest = array_slice($segments, $i + 1);

                return [$next, $rest === [] ? '' : '/' . implode('/', $rest)];
            }

            $path = $next;
        }

        return [$filename, ''];
    }

    /**
     * Merge the sections that apply to a file, in Apache's order.
     *
     * @param list<ApacheNode> $main
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function walk(string $file, string $uri, ?ApacheNode $vhost, array $main, array $context): array
    {
        $config = [
            'allow_override' => ['none' => true], 'options' => ['followsymlinks' => true], 'require' => null, 'directory_index' => ['index.html'],
            'fallback' => null, 'directory_slash' => true, 'accept_path_info' => null, 'handler' => null, 'rewrite' => null, 'redirects' => [],
            'compat' => null, 'at' => [],
        ];
        $sections = [...array_filter($main, fn ($n) => $n->kind === 'block'), ...($vhost !== null ? array_filter($vhost->effective(), fn ($n) => $n->kind === 'block') : [])];
        $directories = array_values(array_filter($sections, fn ($n) => $n->name === 'directory' && $n->arg() !== '~'));
        $regex = array_values(array_filter($sections, fn ($n) => $n->name === 'directorymatch' || ($n->name === 'directory' && $n->arg() === '~')));
        $accessName = $this->first($main, 'accessfilename') ?? '.htaccess';

        // Settings outside any section (main server, then the virtual host) are every directory's starting point:
        // DirectoryIndex, Options, FallbackResource... (mod_rewrite and mod_alias there are server-level, handled before).
        $serverLevel = fn (array $nodes) => array_values(array_filter($nodes, fn (ApacheNode $n) => $n->kind === 'directive'
            && !str_starts_with($n->name, 'rewrite') && !str_starts_with($n->name, 'redirect')));
        $this->merge($config, $serverLevel($main), null, 'server config', $context, false);

        if ($vhost !== null) {
            $this->merge($config, $serverLevel($vhost->effective()), null, $vhost->where(), $context, false);
        }

        $dir = is_dir($file) ? rtrim($file, '/') : dirname($file);
        $levels = ['/'];
        $path = '';

        foreach (array_filter(explode('/', $dir), fn (string $part) => $part !== '') as $part) {
            $path .= '/' . $part;
            $levels[] = $path;
        }

        foreach ($levels as $level) {
            $depth = $level === '/' ? 0 : substr_count($level, '/');

            foreach ($directories as $section) {
                $pattern = rtrim($section->arg(), '/') ?: '/';

                if (($pattern === '/' ? 0 : substr_count($pattern, '/')) === $depth && fnmatch($pattern, $level)) {
                    $this->merge($config, $section->effective(), $level, $section->where(), $context, false, basename($file));
                }
            }

            if (!isset($config['allow_override']['none']) && is_dir($level)) {
                $htaccess = rtrim($level, '/') . '/' . $accessName;

                $text = $this->tree->contents($htaccess);

                if ($text !== null || is_file($htaccess)) {
                    if ($text === null) {
                        $this->step('htaccess', "Can't be read: Apache answers 403.", $htaccess);

                        return ['result' => $this->result(403, 'forbidden')];
                    }

                    $block = new ApacheNode('block', 'htaccess', [], $htaccess, 0, 0);
                    $this->tree->parseText($text, $htaccess, $block);
                    $this->step('htaccess', 'Read: AllowOverride lets it change settings here.', $htaccess);
                    $refused = $this->merge($config, $block->effective(), $level, $htaccess, $context, true, basename($file));

                    if ($refused !== null) {
                        $this->step('htaccess', "$refused: \"not allowed here\", Apache answers 500.", $htaccess);

                        return ['result' => $this->result(500, 'error')];
                    }
                }
            }
        }

        foreach ($regex as $section) {
            $pattern = $section->name === 'directory' ? ($section->args[1] ?? '') : $section->arg();

            if (@preg_match(self::delimit($pattern), $dir) === 1) {
                $this->merge($config, $section->effective(), $dir, $section->where(), $context, false);
            }
        }

        foreach ($sections as $section) {
            if ($section->isBlock('files', 'filesmatch') && $this->filesMatch($section, basename($file))) {
                $this->merge($config, $section->effective(), $dir, $section->where(), $context, false);
            }
        }

        foreach ($sections as $section) {
            if (($section->name === 'location' && $section->arg() !== '~' && ($uri === rtrim($section->arg(), '/') || str_starts_with($uri, rtrim($section->arg(), '/') . '/') || $uri === $section->arg()))
                || ($section->name === 'locationmatch' && @preg_match(self::delimit($section->arg()), $uri) === 1)) {
                $this->merge($config, $section->effective(), null, $section->where(), $context, false);
            }
        }

        return $config;
    }

    private function filesMatch(ApacheNode $section, string $name): bool
    {
        return ($section->name === 'files' && $section->arg() !== '~' && fnmatch($section->arg(), $name))
            || ($section->name === 'filesmatch' && @preg_match(self::delimit($section->arg()), $name) === 1)
            || ($section->name === 'files' && $section->arg() === '~' && @preg_match(self::delimit($section->args[1] ?? ''), $name) === 1);
    }

    /**
     * Apply one section's directives. In a .htaccess file, a directive AllowOverride doesn't allow
     * makes Apache fail the request; its name is returned then.
     *
     * @param array<string, mixed> $config
     * @param list<ApacheNode> $nodes
     * @param array<string, mixed> $context
     */
    private function merge(array &$config, array $nodes, ?string $dir, string $where, array $context, bool $htaccess, ?string $basename = null): ?string
    {
        $allowed = $config['allow_override'];
        $may = fn (string $class) => !$htaccess || isset($allowed['all']) || isset($allowed[$class]);
        $groups = ['options' => 'options', 'directoryindex' => 'indexes', 'fallbackresource' => 'indexes', 'directoryslash' => 'indexes',
            'require' => 'authconfig', 'rewriteengine' => 'fileinfo', 'rewriterule' => 'fileinfo', 'rewritecond' => 'fileinfo', 'rewritebase' => 'fileinfo',
            'rewriteoptions' => 'fileinfo', 'redirect' => 'fileinfo', 'redirectmatch' => 'fileinfo', 'sethandler' => 'fileinfo', 'acceptpathinfo' => 'fileinfo',
            'order' => 'limit', 'allow' => 'limit', 'deny' => 'limit', 'satisfy' => 'authconfig'];
        $requires = null;
        $compat = null;
        $nested = [];

        foreach ($nodes as $node) {
            if ($node->isBlock('requireall', 'requireany', 'requirenone')) {
                if ($htaccess && !$may('authconfig')) {
                    return $node->where() . ': <' . $node->name . '>';
                }

                $requires ??= [];
                $requires[] = $node;

                continue;
            }

            if ($node->kind !== 'directive') {
                if ($node->isBlock('if', 'elseif', 'else')) {
                    $this->warnings[] = "$where has <If> sections: they depend on the request and aren't simulated.";
                } elseif ($node->isBlock('files', 'filesmatch') && $basename !== null && $this->filesMatch($node, $basename)) {
                    // <Files> inside a <Directory> or .htaccess: applies to that file name, after the section itself.
                    $nested[] = $node;
                }

                continue;
            }

            if ($htaccess && isset($groups[$node->name]) && !$may($groups[$node->name])) {
                return $node->where() . ': ' . $node->name;
            }

            switch ($node->name) {
                case 'allowoverride':
                    if (!$htaccess) {
                        $config['allow_override'] = array_fill_keys(array_map('strtolower', $node->args), true);
                    }

                    break;

                case 'options':
                    $config['at']['options'] = $node;
                    $config['options'] = $this->options($config['options'], $node->args);

                    break;

                case 'directoryindex':
                    $config['at']['directoryindex'] = $node;
                    $config['directory_index'] = $node->args;

                    break;

                case 'fallbackresource':
                    $config['at']['fallbackresource'] = $node;
                    $config['fallback'] = $node->arg();

                    break;

                case 'directoryslash':
                    $config['at']['directoryslash'] = $node;
                    $config['directory_slash'] = strtolower($node->arg()) !== 'off';

                    break;

                case 'acceptpathinfo':
                    $config['at']['acceptpathinfo'] = $node;
                    $config['accept_path_info'] = strtolower($node->arg()) === 'on';

                    break;

                case 'sethandler':
                    $config['at']['sethandler'] = $node;
                    $config['handler'] = strtolower($node->arg()) === 'none' ? null : $node->arg();

                    break;

                case 'require':
                    $requires ??= [];
                    $requires[] = $node;

                    break;

                case 'order':
                case 'allow':
                case 'deny':
                case 'satisfy':
                    // mod_access_compat (Apache 2.2 style): a section with any of these starts from the defaults
                    // (checked against a real apache2), it doesn't add to the parent's lists.
                    $compat ??= ['order' => 'deny,allow', 'allow' => [], 'deny' => [], 'satisfy' => 'all', 'where' => $where, 'nodes' => []];
                    $compat['nodes'][] = $node;

                    match ($node->name) {
                        'order' => $compat['order'] = strtolower(str_replace(' ', '', implode('', $node->args))),
                        'satisfy' => $compat['satisfy'] = strtolower($node->arg()),
                        default => array_push($compat[$node->name], ...array_slice($node->args, strtolower($node->arg()) === 'from' ? 1 : 0)),
                    };

                    break;

                case 'redirect':
                case 'redirectmatch':
                case 'redirectpermanent':
                case 'redirecttemp':
                    $config['redirects'][] = $node;

                    break;
            }
        }

        if ($requires !== null) {
            $config['require'] = ['nodes' => $requires, 'where' => $where];
        }

        if ($compat !== null) {
            $config['compat'] = $compat;
        }

        foreach ($nested as $files) {
            $refused = $this->merge($config, $files->effective(), null, $files->where(), $context, $htaccess);

            if ($refused !== null) {
                return $refused;
            }
        }

        $set = RewriteRules::fromNodes($nodes);

        if ($set->present && $dir !== null) {
            $parent = $config['rewrite'];
            $options = array_map('strtolower', $set->options);
            $rules = $set->rules;

            if ($parent !== null && in_array('inherit', $options, true)) {
                $rules = [...$rules, ...$parent['rules']];
            } elseif ($parent !== null && in_array('inheritbefore', $options, true)) {
                $rules = [...$parent['rules'], ...$rules];
            } elseif ($parent !== null && $parent['rules'] !== []) {
                $this->step('rewrite', "$where has its own mod_rewrite directives, so the rules of " . $parent['where'] . ' no longer apply here (no RewriteOptions Inherit).');
            }

            $config['rewrite'] = [
                'rules' => $rules,
                'engine' => $set->engine ?? $parent['engine'] ?? false,
                'base' => $set->base ?? $parent['base'] ?? null,
                'dir' => $dir,
                'where' => $where,
            ];
        } elseif ($set->present && $dir === null) {
            $this->warnings[] = "$where (a <Location>) has mod_rewrite directives; those aren't simulated.";
        }

        return null;
    }

    /**
     * @param array<string, true> $current
     * @param list<string> $args
     * @return array<string, true>
     */
    private function options(array $current, array $args): array
    {
        $relative = $args !== [] && array_filter($args, fn ($a) => $a[0] === '+' || $a[0] === '-') === $args;
        $options = $relative ? $current : [];

        foreach ($args as $arg) {
            $name = strtolower(ltrim($arg, '+-'));
            $names = $name === 'all' ? ['indexes', 'includes', 'followsymlinks', 'execcgi', 'multiviews'] : ($name === 'none' ? [] : [$name]);

            foreach ($names as $option) {
                if ($arg[0] === '-') {
                    unset($options[$option]);
                } else {
                    $options[$option] = true;
                }
            }
        }

        return $options;
    }

    /**
     * Require all granted/denied, local and ip; anything else is noted, not judged.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null a 403 result, or null if allowed
     */
    private function access(array $config, array $context): ?array
    {
        if ($config['require'] === null && $config['compat'] === null) {
            return null;
        }

        $unknown = [];
        $require = $config['require'] === null ? null : $this->authorize($config['require']['nodes'], 'any', $context, $unknown);
        $compat = $config['compat'] === null ? null : $this->compat($config['compat'], (string) $context['remote_addr'], $unknown);
        $any = ($config['compat']['satisfy'] ?? 'all') === 'any';

        if ($config['compat'] !== null) {
            $nodes = $config['compat']['nodes'];
            $this->step(
                'access',
                'Order ' . $config['compat']['order'] . ', Allow from ' . (implode(' ', $config['compat']['allow']) ?: '(nobody)') . ', Deny from ' . (implode(' ', $config['compat']['deny']) ?: '(nobody)')
                . ': ' . ($compat === null ? 'depends on something not simulated' : ($compat ? 'allowed' : 'denied')) . ($any ? '; Satisfy Any' : '') . '.',
                $nodes[0],
                implode("\n", array_map(fn (ApacheNode $n) => $this->written($n), $nodes)),
                implode(', ', array_map(fn (ApacheNode $n) => $n->line, $nodes))
            );
        }

        if ($config['require'] !== null) {
            $nodes = $config['require']['nodes'];
            $this->step(
                'access',
                'Require: ' . ($require === null ? 'depends on something not simulated' : ($require ? 'allowed' : 'denied')) . '.',
                $nodes[0],
                implode("\n", array_map(fn (ApacheNode $n) => $this->written($n), $nodes)),
                implode(', ', array_map(fn (ApacheNode $n) => $n->line, $nodes))
            );
        }

        // Satisfy All (default): both kinds must allow; Satisfy Any: either. A kind that isn't configured allows.
        $results = array_values(array_filter([$config['require'] !== null ? $require : 'absent', $config['compat'] !== null ? $compat : 'absent'], fn ($r) => $r !== 'absent'));
        $allowed = $any
            ? (in_array(true, $results, true) ? true : (in_array(null, $results, true) ? null : false))
            : (in_array(false, $results, true) ? false : (in_array(null, $results, true) ? null : true));

        if ($allowed === null) {
            $this->warnings[] = 'Access depends on "' . implode('", "', $unknown) . '", which the simulator doesn\'t judge; carrying on as if allowed.';

            return null;
        }

        return $allowed ? null : $this->result(403, 'forbidden');
    }

    /**
     * Order / Allow from / Deny from (mod_access_compat). Hosts by name and env= can't be judged here: null.
     *
     * @param array{order: string, allow: list<string>, deny: list<string>} $compat
     * @param list<string> $unknown
     */
    private function compat(array $compat, string $ip, array &$unknown): ?bool
    {
        $matches = function (array $entries) use ($ip, &$unknown): ?bool {
            $result = false;

            foreach ($entries as $entry) {
                $lower = strtolower($entry);

                if ($lower === 'all' || $this->ipMatches($ip, [$entry])) {
                    return true;
                }

                if (str_starts_with($lower, 'env=') || str_starts_with($lower, 'env=!') || preg_match('/[a-z]/i', $entry) === 1 && !str_contains($entry, ':')) {
                    $unknown[] = $entry;
                    $result = null;
                }
            }

            return $result;
        };

        $allow = $matches($compat['allow']);
        $deny = $matches($compat['deny']);

        if ($allow === null || $deny === null) {
            return null;
        }

        // deny,allow: allowed unless denied, and an Allow match wins; allow,deny and mutual-failure: must be allowed and not denied.
        return $compat['order'] === 'deny,allow' ? (!$deny || $allow) : ($allow && !$deny);
    }

    /**
     * Require lines and <RequireAll/Any/None> blocks: true, false, or null when it depends on something not simulated.
     *
     * @param list<ApacheNode> $nodes
     * @param 'any'|'all'|'none' $mode
     * @param array<string, mixed> $context
     * @param list<string> $unknown
     */
    private function authorize(array $nodes, string $mode, array $context, array &$unknown): ?bool
    {
        $results = [];

        foreach ($nodes as $node) {
            if ($node->isBlock('requireall', 'requireany', 'requirenone')) {
                $results[] = $this->authorize($node->effective(), substr($node->name, 7), $context, $unknown);

                continue;
            }

            if ($node->name !== 'require') {
                continue;
            }

            $negate = strtolower($node->arg()) === 'not';
            $args = $negate ? array_slice($node->args, 1) : $node->args;
            $result = match (strtolower($args[0] ?? '')) {
                'all' => strtolower($args[1] ?? '') === 'granted',
                'local' => in_array($context['remote_addr'], ['127.0.0.1', '::1'], true),
                'ip' => $this->ipMatches((string) $context['remote_addr'], array_slice($args, 1)),
                default => null,
            };

            if ($result === null) {
                $unknown[] = implode(' ', $node->args);
            }

            $results[] = $result === null ? null : $result !== $negate;
        }

        return match ($mode) {
            'all' => in_array(false, $results, true) ? false : (in_array(null, $results, true) ? null : true),
            'none' => in_array(true, $results, true) ? false : (in_array(null, $results, true) ? null : true),
            default => in_array(true, $results, true) ? true : (in_array(null, $results, true) ? null : false),
        };
    }

    /**
     * @param list<string> $ranges
     */
    private function ipMatches(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (str_contains($range, '/')) {
                [$net, $bits] = explode('/', $range, 2);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $bits === '0' ? 0 : ~((1 << (32 - (int) $bits)) - 1);

                    if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                        return true;
                    }
                }
            } elseif ($ip === $range || str_starts_with($ip, rtrim($range, '.') . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One step of the trace: what happened, and the directive that did it (as written, with its file and
     * line) when there is one. $at is that directive's node, or a file (a .htaccess read); $lines lists
     * several lines when a step comes from more than one directive.
     */
    private function step(string $phase, string $text, ApacheNode|string|null $at = null, ?string $directive = null, ?string $lines = null): void
    {
        $file = $at instanceof ApacheNode ? $at->file : $at;
        $line = $at instanceof ApacheNode ? (string) $at->line : null;
        $this->steps[] = [
            'phase' => $phase,
            'directive' => $directive ?? ($at instanceof ApacheNode ? $this->written($at) : null),
            'file' => $file === null ? null : self::short($file),
            'line' => $lines ?? $line,
            'text' => $text,
            'where' => $at instanceof ApacheNode ? $at->where() : $file,
        ];
    }

    /**
     * A directive's line as written in its file (a section's opening line; continued lines joined),
     * or its name and arguments when the file can't be read.
     */
    private function written(ApacheNode $node): string
    {
        $lines = ApacheConfigTree::lines((string) $this->tree->contents($node->file));
        $last = $node->kind === 'block' ? $node->line : max($node->line, $node->endLine);
        $text = '';

        for ($i = $node->line; $i <= $last && isset($lines[$i - 1]); $i++) {
            $text .= ($text === '' ? '' : ' ') . trim(rtrim($lines[$i - 1], '\\'));
        }

        return $text !== '' ? $text : ($node->kind === 'block' ? '<' . $node->name . ' ' . implode(' ', $node->rawArgs ?: $node->args) . '>' : $node->name . ' ' . implode(' ', $node->rawArgs ?: $node->args));
    }

    private static function short(string $file): string
    {
        return str_starts_with($file, '/etc/apache2/') ? substr($file, strlen('/etc/apache2/')) : $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(int $status, string $kind, ?string $location = null, ?string $file = null, ?string $pathInfo = null, ?string $handler = null, ?string $text = null): array
    {
        if ($text !== null) {
            $this->step('result', $text);
        }

        return [
            'status' => $status, 'kind' => $kind, 'location' => $location, 'file' => $file, 'path_info' => $pathInfo, 'handler' => $handler,
            'vhost' => null, 'steps' => $this->steps, 'warnings' => array_values(array_unique($this->warnings)),
        ];
    }
}
