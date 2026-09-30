<?php

use App\Utils\BasePath;

afterEach(fn () => BasePath::set(''));

test('the base path is worked out from how the front controller was reached', function (string $script, string $uri, ?string $configured, string $base) {
    expect(BasePath::detect(['SCRIPT_NAME' => $script, 'REQUEST_URI' => $uri], $configured))->toBe($base);
})->with([
    'a site whose root is public/' => ['/index.php', '/mariadb/query', null, ''],
    'the built-in server' => ['/index.php', '/login?x=1', null, ''],
    'a subdirectory, through the top-level .htaccess' => ['/sysadmin/public/index.php', '/sysadmin/login', null, '/sysadmin'],
    'its front page' => ['/sysadmin/public/index.php', '/sysadmin/', null, '/sysadmin'],
    'deeper' => ['/tools/sysadmin/public/index.php', '/tools/sysadmin/ssh/reports', null, '/tools/sysadmin'],
    'public/ reached directly' => ['/sysadmin/public/index.php', '/sysadmin/public/login', null, '/sysadmin/public'],
    'an Alias to public/' => ['/sysadmin/index.php', '/sysadmin/login', null, '/sysadmin'],
    'a site root that is the app folder' => ['/public/index.php', '/login', null, ''],
    'set in the environment' => ['/index.php', '/login', '/admin-tools/', '/admin-tools'],
]);

test('the routes see the path without the base', function () {
    BasePath::set('/sysadmin');

    expect(BasePath::path('/sysadmin/ssh/reports?x=1'))->toBe('/ssh/reports')
        ->and(BasePath::path('/sysadmin'))->toBe('/')
        ->and(BasePath::path('/sysadminx/y'))->toBe('/sysadminx/y')
        ->and(BasePath::to('/login'))->toBe('/sysadmin/login');
    BasePath::set('');
    expect(BasePath::path('/ssh/reports'))->toBe('/ssh/reports')->and(BasePath::to('/login'))->toBe('/login');
});

test('the app\'s own URLs in a page get the base; everything else is left as it is', function () {
    $html = <<<'HTML'
        <!doctype html><html lang="en"><head><link rel="icon" href="/assets/icon.svg"><script src="/assets/js/paged-table.js?v=1"></script></head>
        <body><a href="/mariadb/query">Query</a> <a href="https://example.com/x">out</a> <a href="//cdn.example.com/y">cdn</a> <a href="#accounts">in page</a>
        <form action="/mariadb/users" method="post"><input type="hidden" name="back" value="/ssh"><button formaction="/logout">x</button></form>
        <div data-paged="/apache/reports/entries?kind=days&amp;range=24h"></div><code class="request-path" data-path="/admin.php">GET /admin.php</code>
        <a href="/sysadmin/already">already</a>
        <script>fetch('/mariadb/users/names?' + params); var p = "/admin/servers/" + id; var logged = '/wp-login.php'; var r = `/profile#passkeys`;</script>
        </body></html>
        HTML;
    $out = BasePath::rewriteHtml($html, '/sysadmin');

    expect($out)->toContain('<html data-base="/sysadmin" lang="en">')
        ->toContain('href="/sysadmin/assets/icon.svg"')->toContain('src="/sysadmin/assets/js/paged-table.js?v=1"')
        ->toContain('href="/sysadmin/mariadb/query"')->toContain('action="/sysadmin/mariadb/users"')->toContain('formaction="/sysadmin/logout"')
        ->toContain('data-paged="/sysadmin/apache/reports/entries?kind=days&amp;range=24h"')
        ->toContain("fetch('/sysadmin/mariadb/users/names?'")->toContain('"/sysadmin/admin/servers/"')->toContain('`/sysadmin/profile#passkeys`')
        // Not the app's: other sites, protocol-relative, fragments, form values, logged request paths, other strings, already prefixed.
        ->toContain('href="https://example.com/x"')->toContain('href="//cdn.example.com/y"')->toContain('href="#accounts"')
        ->toContain('value="/ssh"')->toContain('data-path="/admin.php">GET /admin.php')->toContain("'/wp-login.php'")
        ->toContain('href="/sysadmin/already"')->not->toContain('/sysadmin/sysadmin')
        ->and(BasePath::rewriteHtml($html, ''))->toBe($html);
});

test('JSON: HTML fragments and redirects get the base', function () {
    $json = json_encode(['html' => '<tr><td><a href="/vhosts/reports?vhost=3">x</a></td></tr>', 'total' => 3, 'redirect' => '/', 'error' => 'Path /etc/x missing']);
    $out = json_decode(BasePath::rewriteJson((string) $json, '/sysadmin'), true);

    expect($out['html'])->toBe('<tr><td><a href="/sysadmin/vhosts/reports?vhost=3">x</a></td></tr>')
        ->and($out['redirect'])->toBe('/sysadmin/')
        ->and($out['total'])->toBe(3)
        ->and($out['error'])->toBe('Path /etc/x missing')
        ->and(BasePath::rewriteJson((string) $json, ''))->toBe($json);
});

test('responses are rewritten by type, and not at all at a site\'s root', function () {
    BasePath::set('/sysadmin');

    expect(BasePath::rewriteOutput('<a href="/login">', ['Content-Type: text/html; charset=UTF-8']))->toBe('<a href="/sysadmin/login">')
        ->and(BasePath::rewriteOutput('{"redirect":"/profile"}', ['Content-Type: application/json']))->toBe('{"redirect":"/sysadmin/profile"}')
        ->and(BasePath::rewriteOutput('a,href="/x"', ['Content-Type: text/csv']))->toBe('a,href="/x"');
    BasePath::set('');
    expect(BasePath::rewriteOutput('<a href="/login">', []))->toBe('<a href="/login">');
});
