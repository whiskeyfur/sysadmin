<?php

namespace App\Utils;

/**
 * Where the app lives on its web server: '' at a site's root (sys.localhost), or e.g. '/sysadmin' when it's
 * installed in a subdirectory of another site (/var/www/html/sysadmin/, the top-level .htaccess sending
 * every request into public/). Worked out once per request in public/index.php (detect()), and handed to
 * Leaf's router and redirects.
 *
 * Views, controllers and scripts keep writing root-relative app paths (/mariadb/query); under a
 * subdirectory, rewrite() prefixes them in the app's HTML and JSON output, and the scripts in
 * public/assets/js read the base from <html data-base>. At a site's root nothing is rewritten.
 */
class BasePath
{
    /**
     * First path segments of the app's own URLs: string literals in inline scripts starting with one of
     * these are app paths (other strings, e.g. a request path in the access log, are left alone).
     */
    public const PREFIXES = ['admin', 'apache', 'assets', 'install', 'login', 'logout', 'mariadb', 'profile', 'servers', 'ssh', 'ssl', 'vhost', 'vhosts'];

    private static string $base = '';

    /**
     * The base path for a request: APP_BASE_PATH when set (e.g. behind a proxy that strips a prefix),
     * else from where the front controller was reached. public/index.php reached as
     * /sysadmin/public/index.php for /sysadmin/login (the top-level .htaccess) means '/sysadmin'; an Alias
     * or a DocumentRoot pointing at public/ makes the script's own directory the base; the built-in
     * server and a site root give ''.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function detect(array $server, ?string $configured = null): string
    {
        if ($configured !== null && trim($configured) !== '') {
            return self::normalise($configured);
        }

        $dir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php')));
        $dir = self::normalise($dir);
        $request = rawurldecode((string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

        if ($dir === '' || $request === $dir || str_starts_with($request, $dir . '/')) {
            return $dir;
        }

        // Rewritten into public/ by the top-level .htaccess: the app's own directory is the base.
        return str_ends_with($dir, '/public') ? substr($dir, 0, -strlen('/public')) : $dir;
    }

    public static function set(string $base): void
    {
        self::$base = self::normalise($base);
    }

    public static function get(): string
    {
        return self::$base;
    }

    /**
     * An app path as a URL on this install: '/login' → '/sysadmin/login'.
     */
    public static function to(string $path): string
    {
        return self::$base . '/' . ltrim($path, '/');
    }

    /**
     * The request path without the base, as the routes see it: '/sysadmin/ssh/reports' → '/ssh/reports'.
     */
    public static function path(?string $requestUri = null): string
    {
        $path = parse_url($requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

        if (self::$base !== '' && ($path === self::$base || str_starts_with($path, self::$base . '/'))) {
            $path = substr($path, strlen(self::$base)) ?: '/';
        }

        return $path;
    }

    /**
     * Prefix the app's root-relative URLs in an HTML page or fragment: href, src, action, formaction and
     * data-paged attributes, and string literals naming an app path (PREFIXES) inside inline scripts. The
     * <html> tag gets data-base for the scripts in public/assets/js. Nothing changes at a site's root.
     */
    public static function rewriteHtml(string $html, ?string $base = null): string
    {
        $base ??= self::$base;

        if ($base === '') {
            return $html;
        }

        $quoted = preg_quote($base, '~');
        // Attributes: a value starting with one "/" (not "//host", not already under the base).
        $html = (string) preg_replace('~(\s(?:href|src|action|formaction|data-paged)=(["\']))/(?!/)(?!' . substr($quoted, 1) . '(?:[/?#"\']))~i', '$1' . $base . '/', $html);

        // Inline scripts: '/mariadb/...' and the like.
        $prefixes = implode('|', self::PREFIXES);
        $html = (string) preg_replace_callback('~(<script\b(?![^>]*\bsrc=)[^>]*>)(.*?)(</script>)~is', fn ($m) => $m[1]
            . preg_replace('~(["\'`])/(?=(?:' . $prefixes . ')(?:[/?#"\'`]|$))~', '$1' . $base . '/', $m[2]) . $m[3], $html);

        return (string) preg_replace('~<html\b(?![^>]*\bdata-base=)~i', '<html data-base="' . htmlspecialchars($base, ENT_QUOTES) . '"', $html, 1);
    }

    /**
     * The same for a JSON response: every string that holds HTML is rewritten, and a "redirect" (or
     * "location"/"url") that's an app path gets the base.
     */
    public static function rewriteJson(string $json, ?string $base = null): string
    {
        $base ??= self::$base;
        $data = json_decode($json, true);

        if ($base === '' || !is_array($data)) {
            return $json;
        }

        $walk = function (mixed $value, int|string $key) use (&$walk, $base): mixed {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $walk($v, $k);
                }

                return $value;
            }

            if (!is_string($value)) {
                return $value;
            }

            if (in_array($key, ['redirect', 'location', 'url'], true) && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
                return str_starts_with($value, $base . '/') || $value === $base ? $value : $base . $value;
            }

            return str_contains($value, '<') ? self::rewriteHtml($value, $base) : $value;
        };

        return (string) json_encode($walk($data, ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Rewrite a response body by its Content-Type header (HTML or JSON; anything else as it is).
     *
     * @param list<string> $headers headers_list()
     */
    public static function rewriteOutput(string $body, array $headers): string
    {
        if (self::$base === '') {
            return $body;
        }

        $type = 'text/html';

        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $type = strtolower(trim(substr($header, 13)));
            }
        }

        return match (true) {
            str_contains($type, 'json') => self::rewriteJson($body),
            str_contains($type, 'html') => self::rewriteHtml($body),
            default => $body,
        };
    }

    private static function normalise(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');

        return $path === '/' || $path === '/.' ? '' : $path;
    }
}
