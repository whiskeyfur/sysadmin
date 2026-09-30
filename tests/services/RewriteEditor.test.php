<?php

use App\Models\User;
use App\Services\LocalApacheService;
use App\Services\RewriteEditorService;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/rewrite-editor-' . bin2hex(random_bytes(4));
    $root = "{$this->dir}/apache2";
    $docs = "{$this->dir}/www";
    $bin = "{$this->dir}/bin";

    foreach (["$root/sites-available", "$root/sites-enabled", "$root/conf-available", "$root/conf-enabled", "$root/mods-available", "$root/mods-enabled", "$docs/blog", $bin] as $dir) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents("$root/apache2.conf", "ServerRoot $root\nLoadModule rewrite_module mod_rewrite.so\n<Directory />\n    AllowOverride None\n</Directory>\n<Directory $docs/>\n    AllowOverride All\n    Require all granted\n</Directory>\nIncludeOptional sites-enabled/*.conf\n");
    file_put_contents("$root/sites-available/shop.conf", "<VirtualHost *:80>\n    ServerName shop.test\n    DocumentRoot $docs\n    # keep this comment\n    RewriteEngine On\n    RewriteRule ^/old$ /new [R=301,L]\n    # between the rules\n    RewriteCond %{HTTP_HOST} ^www\\. [NC]\n    RewriteRule ^/(.*)$ http://shop.test/$1 [R=301,L]\n</VirtualHost>\n");
    symlink('../sites-available/shop.conf', "$root/sites-enabled/shop.conf");
    file_put_contents("$docs/.htaccess", "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n");
    chmod("$docs/.htaccess", 0o640);
    file_put_contents("$docs/index.php", 'front');
    file_put_contents("$docs/new", 'new');
    file_put_contents("$bin/apache2ctl", "#!/bin/sh\n[ \"\$1\" = configtest ] && { grep -Rqs BROKEN $root/sites-enabled/ && { echo 'Syntax error' >&2; exit 1; }; echo 'Syntax OK' >&2; }\nexit 0\n");
    chmod("$bin/apache2ctl", 0o755);

    $this->root = $root;
    $this->docs = $docs;
    $apache = new LocalApacheService([PHP_BINARY, 'bin/sys-apache-helper'], ['SYS_APACHE_ROOT' => $root, 'SYS_APACHE_BIN' => $bin, 'SYS_APACHE_BACKUPS' => "{$this->dir}/backups"]);
    $this->editor = new RewriteEditorService($apache, $root);
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->vhost = fn () => collect($this->editor->scopes())->firstWhere('kind', 'virtualhost')['id'];
    $this->rule = fn (string $pattern, string $to, array $flags = [['L', null]], array $conds = []) => ['conds' => $conds, 'pattern' => $pattern, 'substitution' => $to, 'flags' => $flags];
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('every place rules can be is listed: virtual hosts, <Directory> sections, .htaccess files and served directories without one', function () {
    $scopes = collect($this->editor->scopes())->keyBy('title');

    expect($scopes->keys()->all())->toContain('VirtualHost *:80 (shop.test)', "{$this->docs}/.htaccess")
        ->and($scopes['VirtualHost *:80 (shop.test)']['rules'])->toBe(2)
        ->and($scopes["{$this->docs}/.htaccess"]['rules'])->toBe(2)
        ->and($this->editor->scope("htaccess:{$this->docs}/blog")['exists'])->toBeFalse();
});

test('changing one rule rewrites only its lines; comments and the other rules stay', function () {
    $scope = $this->editor->scope(($this->vhost)());
    $text = $this->editor->withRule($scope, 0, ($this->rule)('^/old$', '/newer', [['R', '302'], ['L', null]]));

    expect($text)->toContain("    # keep this comment\n    RewriteEngine On\n    RewriteRule ^/old$ /newer [R=302,L]\n    # between the rules\n    RewriteCond %{HTTP_HOST} ^www\\. [NC]")
        ->and(substr_count($text, 'RewriteRule'))->toBe(2);
});

test('rules are added after the last one (inside <IfModule> in .htaccess), moved around the lines between them, and deleted', function () {
    $htaccess = $this->editor->scope("htaccess:{$this->docs}");
    $added = $this->editor->withRule($htaccess, null, ($this->rule)('^feed$', '/index.php?feed=1', [['QSA', null], ['L', null]]));

    expect($added)->toContain("RewriteRule . /index.php [L]\nRewriteRule ^feed$ /index.php?feed=1 [QSA,L]\n</IfModule>");

    $vhost = $this->editor->scope(($this->vhost)());
    $moved = $this->editor->withRuleMoved($vhost, 1, -1);

    expect($moved)->toContain("    RewriteEngine On\n    RewriteCond %{HTTP_HOST} ^www\\. [NC]\n    RewriteRule ^/(.*)$ http://shop.test/$1 [R=301,L]\n    # between the rules\n    RewriteRule ^/old$ /new [R=301,L]\n</VirtualHost>")
        ->and($this->editor->withoutRule($vhost, 1))->not->toContain('RewriteCond')->toContain('# between the rules');
});

test('a scope without rules gets RewriteEngine On with its first rule; settings set the engine and base', function () {
    $blog = $this->editor->scope("htaccess:{$this->docs}/blog");
    $text = $this->editor->withRule($blog, null, ($this->rule)('^(.*)$', 'index.php', [['L', null]]));

    expect($text)->toBe("RewriteEngine On\nRewriteRule ^(.*)$ index.php [L]\n");

    $wordpress = $this->editor->scope("htaccess:{$this->docs}");
    $settings = $this->editor->withSettings($wordpress, false, '/shop/');

    expect($settings)->toContain("RewriteEngine Off\nRewriteBase /shop/\n")
        ->and($this->editor->withSettings($wordpress, true, ''))->not->toContain('RewriteBase');
});

test('saving goes through the root helper: configuration files are configtested, .htaccess keeps its mode, a changed file isn\'t overwritten', function () {
    $vhost = $this->editor->scope(($this->vhost)());
    $this->editor->save($this->admin, $vhost, $vhost['hash'], $this->editor->withRule($vhost, 0, ($this->rule)('^/old$', '/newest')));

    expect(file_get_contents("{$this->root}/sites-available/shop.conf"))->toContain('RewriteRule ^/old$ /newest [L]');

    expect(fn () => $this->editor->save($this->admin, $vhost, $vhost['hash'], 'x'))->toThrow(DomainException::class, 'changed since');

    $vhost = $this->editor->scope(($this->vhost)());
    expect(fn () => $this->editor->save($this->admin, $vhost, $vhost['hash'], str_replace('ServerName', "BROKEN\nServerName", $vhost['text'])))->toThrow(DomainException::class, 'undone');

    $htaccess = $this->editor->scope("htaccess:{$this->docs}");
    $this->editor->save($this->admin, $htaccess, $htaccess['hash'], $this->editor->withSettings($htaccess, true, '/wp/'));

    $blog = $this->editor->scope("htaccess:{$this->docs}/blog");
    $this->editor->save($this->admin, $blog, $blog['hash'], $this->editor->withRule($blog, null, ($this->rule)('^x$', 'y')));

    expect(file_get_contents("{$this->docs}/.htaccess"))->toContain('RewriteBase /wp/')
        ->and(fileperms("{$this->docs}/.htaccess") & 0o777)->toBe(0o640)
        ->and(file_get_contents("{$this->docs}/blog/.htaccess"))->toBe("RewriteEngine On\nRewriteRule ^x$ y [L]\n");
});

test('the helper refuses .htaccess files outside the served directories', function () {
    $outside = "{$this->dir}/elsewhere";
    mkdir($outside);

    expect(fn () => $this->editor->scope("htaccess:$outside"))->toThrow(DomainException::class, 'served')
        ->and(fn () => (new LocalApacheService([PHP_BINARY, 'bin/sys-apache-helper'], ['SYS_APACHE_ROOT' => $this->root, 'SYS_APACHE_BIN' => "{$this->dir}/bin", 'SYS_APACHE_BACKUPS' => "{$this->dir}/b"]))
            ->writeHtaccess($this->admin, $outside, "RewriteEngine On\n", true))->toThrow(DomainException::class, 'not a DocumentRoot');
});

test('bad rules are refused before anything is written', function (array $rule, string $error) {
    $vhost = $this->editor->scope(($this->vhost)());

    expect(fn () => $this->editor->withRule($vhost, null, $rule))->toThrow(DomainException::class, $error);
})->with([
    'bad regex' => [['conds' => [], 'pattern' => '^(unclosed', 'substitution' => '/x', 'flags' => []], 'valid regular expression'],
    'newline' => [['conds' => [], 'pattern' => "^a\nRewriteRule", 'substitution' => '/x', 'flags' => []], 'one line'],
    'unknown flag' => [['conds' => [], 'pattern' => '^a', 'substitution' => '/x', 'flags' => [['ZZ', null]]], 'Unknown flag'],
    'bad R code' => [['conds' => [], 'pattern' => '^a', 'substitution' => '/x', 'flags' => [['R', '200']]], '3xx'],
    'bad cond regex' => [['conds' => [['test' => '%{HTTP_HOST}', 'pattern' => '(x', 'flags' => []]], 'pattern' => '^a', 'substitution' => '/x', 'flags' => []], 'condition pattern'],
]);

test('a change can be tried on a URL before saving it', function () {
    $vhost = $this->editor->scope(($this->vhost)());
    $text = $this->editor->withRule($vhost, 0, ($this->rule)('^/old$', '/elsewhere', [['R', '302'], ['L', null]]));

    $before = $this->editor->simulator()->simulate('http://shop.test/old');
    $after = $this->editor->simulator($vhost['file'], $text)->simulate('http://shop.test/old');

    expect($before['location'])->toBe('http://shop.test/new')
        ->and($after['status'])->toBe(302)
        ->and($after['location'])->toBe('http://shop.test/elsewhere')
        ->and(file_get_contents("{$this->root}/sites-available/shop.conf"))->toContain('/new [R=301,L]');
});
