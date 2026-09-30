<?php

use App\Services\ApacheConfigTree;
use App\Services\ApacheSimulator;

/*
 * The simulator against the real thing: a throwaway apache2 (the machine's own binary, run as this user on a
 * spare port) with the same configuration and files; every URL must give the same status, Location, and file.
 */

beforeAll(function () {
    if (!is_executable('/usr/sbin/apache2')) {
        return;
    }
});

function simulatorWorld(): array
{
    static $world = null;

    if ($world !== null) {
        return $world;
    }

    $t = sys_get_temp_dir() . '/apache-sim-' . bin2hex(random_bytes(4));
    $port = 18000 + random_int(0, 9000);
    $files = [
        'a/index.html', 'a/target.html', 'a/new/x.html', 'a/items/7.html', 'a/blog/index.html', 'a/blog/real.html', 'a/shop/product.html',
        'a/sub/index.html', 'a/empty/nothing.txt', 'a/deny/f.html', 'a/noov/f.html', 'a/badov/f.html', 'static/s.css', 'b/index.html', 'b/landing',
        'b/app/index.html', 'a/loop/start', 'a/end/b', 'a/end/c', 'static/app/page.html', 'a/script.html', 'a/phpidx/index.php', 'a/compat/d1/x.html', 'a/compat/d1/sub/x.html', 'a/compat/d1/opt/x.html', 'a/compat/d1/req/x.html', 'a/compat/d1/allow/x.html',
        'a/compat/d2/x.html', 'a/compat/d3/x.html', 'a/compat/d4/x.html', 'a/compat/d5/x.html', 'a/compat/d6/x.html', 'a/compat/f1/c/x.html', 'a/compat/f2/x.html',
        'a/compat/f2/c/x.html', 'a/compat/lim/x.html', 'a/front/router.html', 'a/front/real.html', 'a/front/.env', 'a/inh/x.html', 'a/inh/target2.html', 'a/inh/keep/x.html', 'a/inh/drop/x.html', 'a/inh/own/x.html', 'a/inh/own/y.html',
        'a/inh/both/x.html', 'a/inh/both/y.html', 'a/listing/one.txt', 'a/ci/Page.html', 'a/or/ok.html',
    ];

    foreach ($files as $file) {
        @mkdir(dirname("$t/$file"), 0o755, true);
        file_put_contents("$t/$file", "FILE $t/$file\n");
    }

    $htaccess = [
        'a/blog' => "RewriteEngine On\nRewriteBase /blog/\nRewriteRule ^index\\.html$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /blog/index.html [L]\n",
        'a/shop' => "RewriteEngine On\nRewriteRule ^p/(\\d+)$ product.html?id=$1 [L,QSA]\n",
        'a/deny' => "Require all denied\n",
        'a/noov' => "RewriteEngine On\nRewriteRule .* /target.html [L]\n",
        'a/badov' => "RewriteEngine On\n",
        'a/inh' => "RewriteEngine On\nRewriteRule ^(.*)x\\.html$ /inh/target2.html [L]\n",
        'a/inh/keep' => "Options +FollowSymLinks\n",
        'a/inh/drop' => "RewriteEngine On\n",
        'a/inh/own' => "RewriteEngine On\nRewriteRule ^y\\.html$ /target.html [L]\n",
        'a/inh/both' => "RewriteEngine On\nRewriteOptions Inherit\nRewriteRule ^y\\.html$ /target.html [L]\n",
        'a/ci' => "RewriteEngine On\nRewriteRule ^page\\.html$ Page.html [NC,L]\n",
        'a/loop' => "RewriteEngine On\nRewriteRule ^a$ b [L]\nRewriteRule ^b$ a [L]\n",
        'a/end' => "RewriteEngine On\nRewriteRule ^a$ b [END]\nRewriteRule ^b$ c [L]\n",
        'static/app' => "RewriteEngine On\nRewriteBase /static/app/\nRewriteRule ^go$ page.html [L]\n",
        'a/front' => "RewriteEngine On\nRewriteRule ^$ /target.html [R=302,L]\nFallbackResource /front/router.html\n<RequireAll>\n    Require all granted\n</RequireAll>\n<FilesMatch \"^\\.\">\n    Require all denied\n</FilesMatch>\nRedirectMatch 404 /\\.git\n",
        'a/compat/d1' => "Order deny,allow\nDeny from all\n",
        'a/compat/d1/opt' => "Options -Indexes\n",
        'a/compat/d1/req' => "Require all granted\n",
        'a/compat/d1/allow' => "Allow from all\n",
        'a/compat/d2' => "Order deny,allow\nDeny from all\nAllow from 127.0.0.1\n",
        'a/compat/d3' => "Order allow,deny\n",
        'a/compat/d4' => "Require all denied\nOrder deny,allow\nAllow from 127.0.0.1\nSatisfy Any\n",
        'a/compat/d5' => "Require all denied\nAllow from 127.0.0.1\n",
        'a/compat/d6' => "Deny from 127.0.0.0/8\n",
        'a/compat/f1' => "Order allow,deny\nAllow from 127.0.0.1\n",
        'a/compat/f1/c' => "Allow from 10.0.0.1\n",
        'a/compat/f2' => "Order deny,allow\nDeny from 127.0\n",
        'a/compat/f2/c' => "Deny from 10.0.0.1\n",
        'a/compat/lim' => "Order deny,allow\n",
        'a/or' => "RewriteEngine On\nRewriteCond %{QUERY_STRING} a=1 [OR]\nRewriteCond %{QUERY_STRING} b=1\nRewriteRule ^in$ ok.html [L]\n",
    ];

    foreach ($htaccess as $dir => $text) {
        @mkdir("$t/$dir", 0o755, true);
        file_put_contents("$t/$dir/.htaccess", $text);
    }

    $modules = '';

    foreach (['mpm_prefork', 'authz_core', 'authz_host', 'access_compat', 'mime', 'dir', 'alias', 'rewrite', 'autoindex'] as $module) {
        $modules .= "LoadModule {$module}_module /usr/lib/apache2/modules/mod_$module.so\n";
    }

    file_put_contents("$t/httpd.conf", <<<CONF
        ServerRoot $t
        PidFile $t/httpd.pid
        Mutex file:$t
        Listen 127.0.0.1:$port
        ServerName localhost
        ErrorLog $t/error.log
        $modules
        TypesConfig /etc/mime.types
        DocumentRoot $t/a
        <IfModule mod_dir.c>
            DirectoryIndex index.html index.cgi index.php
        </IfModule>
        <Directory />
            AllowOverride None
            Require all denied
        </Directory>
        <Directory $t/>
            AllowOverride All
            Options FollowSymLinks
            Require all granted
        </Directory>
        <Directory $t/a/noov>
            AllowOverride None
        </Directory>
        <Directory $t/a/badov>
            AllowOverride Indexes
        </Directory>
        <Directory $t/a/listing>
            Options +Indexes
        </Directory>
        <Directory $t/a/compat/lim>
            AllowOverride AuthConfig
        </Directory>
        LoadModule status_module /usr/lib/apache2/modules/mod_status.so
        <Location /front/status>
            SetHandler server-status
            Require local
        </Location>
        <VirtualHost *:$port>
            ServerName a.test
            DocumentRoot $t/a
            RewriteEngine On
            RewriteRule ^/old/(.*)$ /new/$1 [R=301,L]
            RewriteRule ^/moved$ http://b.test/landing [R,L]
            RewriteRule ^/internal$ /target.html [L]
            RewriteRule ^/forbid - [F]
            RewriteRule ^/gone - [G]
            RewriteCond %{QUERY_STRING} ^id=(\\d+)$
            RewriteRule ^/item$ /items/%1.html? [L]
            RewriteRule ^/assets/(.*)$ /static/$1 [PT]
            RewriteRule ^/fs$ $t/a/target.html [L]
            RewriteRule ^/qsa$ /target.html?x=1 [R=302,QSA,L]
            Redirect 302 /redir /target.html
            RedirectMatch 301 ^/rm/(.*)$ /target.html?x=$1
            Alias /static $t/static
        </VirtualHost>
        <VirtualHost *:$port>
            ServerName b.test
            ServerAlias *.b.test
            DocumentRoot $t/b
            <Directory $t/b/app>
                FallbackResource /app/index.html
            </Directory>
        </VirtualHost>
        CONF);

    exec('/usr/sbin/apache2 -f ' . escapeshellarg("$t/httpd.conf") . ' -k start 2>&1', $out, $code);

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $tree = new ApacheConfigTree($t, 'httpd.conf');
    $world = ['dir' => $t, 'port' => $port, 'simulator' => new ApacheSimulator($tree->load(), $tree), 'started' => $code === 0];
    register_shutdown_function(function () use ($t) {
        exec('/usr/sbin/apache2 -f ' . escapeshellarg("$t/httpd.conf") . ' -k stop 2>&1');
        usleep(300000);
        exec('rm -rf ' . escapeshellarg($t));
    });

    return $world;
}

function realApache(string $host, string $path, int $port): array
{
    $socket = fsockopen('127.0.0.1', $port, $errno, $error, 5);
    fwrite($socket, "GET $path HTTP/1.0\r\nHost: $host:$port\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);
    [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
    preg_match('#^HTTP/\S+ (\d+)#', $head, $status);
    preg_match('/^Location: (.+)$/mi', $head, $location);

    return ['status' => (int) ($status[1] ?? 0), 'location' => isset($location[1]) ? trim($location[1]) : null, 'body' => trim($body)];
}

test('the simulator agrees with a real apache2', function (string $host, string $path, ?int $expected = null) {
    $world = simulatorWorld();

    if (!$world['started']) {
        $this->markTestSkipped('apache2 could not be started here.');
    }

    $real = realApache($host, $path, $world['port']);
    $sim = $world['simulator']->simulate("http://$host:{$world['port']}$path");
    $explain = "\nreal: " . json_encode($real) . "\nsimulated: " . json_encode(array_diff_key($sim, ['steps' => 1])) . "\n" . implode("\n", array_column($sim['steps'], 'text'));

    if ($expected !== null) {
        expect($real['status'])->toBe($expected, "The real server gave something else for $host$path" . $explain);
    }

    expect($sim['status'])->toBe($real['status'], "Status for $host$path" . $explain);

    if ($real['location'] !== null) {
        expect($sim['location'])->toBe($real['location'], "Location for $host$path" . $explain);
    }

    if ($real['status'] === 200 && str_starts_with($real['body'], 'FILE ')) {
        expect('FILE ' . $sim['file'])->toBe($real['body'], "File for $host$path" . $explain);
    }
})->with([
    ['a.test', '/', 200],
    ['a.test', '/old/x.html', 301],
    ['a.test', '/moved', 302],
    ['a.test', '/internal', 200],
    ['a.test', '/forbid', 403],
    ['a.test', '/gone', 410],
    ['a.test', '/item?id=7', 200],
    ['a.test', '/item?id=x', 404],
    ['a.test', '/redir', 302],
    ['a.test', '/rm/abc', 301],
    ['a.test', '/static/s.css', 200],
    ['a.test', '/blog/some/post', 200],
    ['a.test', '/blog/real.html', 200],
    ['a.test', '/shop/p/5', 200],
    ['a.test', '/shop/p/5?ref=x', 200],
    ['a.test', '/deny/f.html', 403],
    ['a.test', '/noov/f.html', 200],
    ['a.test', '/badov/f.html', 500],
    ['a.test', '/sub', 301],
    ['a.test', '/sub/', 200],
    ['a.test', '/empty/', 403],
    ['a.test', '/listing/', 200],
    ['a.test', '/nothere.html', 404],
    ['a.test', '/inh/x.html', 200],
    ['a.test', '/inh/keep/x.html', 200],
    ['a.test', '/inh/drop/x.html', 200],
    ['a.test', '/inh/own/x.html', 200],
    ['a.test', '/inh/own/y.html', 200],
    ['a.test', '/inh/both/x.html', 200],
    ['a.test', '/inh/both/y.html', 200],
    ['a.test', '/ci/PAGE.html', 200],
    ['a.test', '/or/in?b=1', 200],
    ['a.test', '/or/in?c=1', 404],
    ['a.test', '/assets/s.css', 200],
    ['a.test', '/fs', 200],
    ['a.test', '/qsa?y=2', 302],
    ['a.test', '/loop/a', 500],
    ['a.test', '/end/a', 200],
    ['a.test', '/end/b', 200],
    ['a.test', '/static/app/go', 200],
    ['a.test', '/script.html/extra', 404],
    ['a.test', '/phpidx/', 200],
    ['127.0.0.1', '/phpidx/', 200],
    ['a.test', '/front/', 302],
    ['a.test', '/front/some/route', 200],
    ['a.test', '/front/real.html', 200],
    ['a.test', '/front/.git/config', 403],
    ['a.test', '/nothere/deeper/x', 404],
    ['a.test', '/front/status', 200],
    ['a.test', '/front/.env', 403],
    ['a.test', '/compat/d1/x.html', 403],
    ['a.test', '/compat/d1/sub/x.html', 403],
    ['a.test', '/compat/d1/opt/x.html', 403],
    ['a.test', '/compat/d1/req/x.html', 403],
    ['a.test', '/compat/d1/allow/x.html', 200],
    ['a.test', '/compat/d2/x.html', 200],
    ['a.test', '/compat/d3/x.html', 403],
    ['a.test', '/compat/d4/x.html', 200],
    ['a.test', '/compat/d5/x.html', 403],
    ['a.test', '/compat/d6/x.html', 403],
    ['a.test', '/compat/f1/c/x.html', 200],
    ['a.test', '/compat/f2/x.html', 403],
    ['a.test', '/compat/f2/c/x.html', 200],
    ['a.test', '/compat/lim/x.html', 500],
    ['b.test', '/', 200],
    ['x.b.test', '/landing', 200],
    ['b.test', '/app/some/route', 200],
    ['unknown.test', '/target.html', 200],
]);
