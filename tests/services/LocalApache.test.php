<?php

use App\Exceptions\AuthorizationException;
use App\Models\ApacheAdminLog;
use App\Models\User;
use App\Services\LocalApacheService;

beforeEach(function () {
    // A throwaway /etc/apache2 and stand-ins for Apache's tools; the real helper script runs against them.
    $this->dir = sys_get_temp_dir() . '/local-apache-' . bin2hex(random_bytes(4));
    $conf = "{$this->dir}/apache2";
    $bin = "{$this->dir}/bin";

    foreach (['sites', 'conf', 'mods'] as $kind) {
        mkdir("$conf/$kind-available", 0o755, true);
        mkdir("$conf/$kind-enabled");
    }

    mkdir($bin);
    file_put_contents("$conf/apache2.conf", "ServerRoot /etc/apache2\nIncludeOptional sites-enabled/*.conf\n");
    file_put_contents("$conf/ports.conf", "Listen 80\n");
    file_put_contents("$conf/sites-available/shop.conf", "<VirtualHost *:80>\nServerName shop.test\n</VirtualHost>\n");
    file_put_contents("$conf/sites-available/sys.conf", '<VirtualHost *:5015>' . "\nDocumentRoot " . dirname(__DIR__, 2) . "/public\n</VirtualHost>\n");
    symlink('../sites-available/shop.conf', "$conf/sites-enabled/shop.conf");
    file_put_contents("$conf/mods-available/rewrite.load", "LoadModule rewrite_module x.so\n");

    file_put_contents("$bin/apache2ctl", "#!/bin/sh\ncase \"\$1\" in\n  -v) echo 'Server version: Apache/2.4.58 (Ubuntu)';;\n  configtest) if grep -Rqs BROKEN $conf/apache2.conf $conf/ports.conf $conf/sites-enabled/ $conf/conf-enabled/; then echo 'AH00526: Syntax error: BROKEN' >&2; exit 1; fi; echo 'Syntax OK' >&2;;\nesac\n");
    foreach (['a2ensite' => ['sites', '.conf', true], 'a2dissite' => ['sites', '.conf', false], 'a2enconf' => ['conf', '.conf', true], 'a2disconf' => ['conf', '.conf', false], 'a2enmod' => ['mods', '.load', true], 'a2dismod' => ['mods', '.load', false]] as $tool => [$kind, $suffix, $on]) {
        file_put_contents("$bin/$tool", "#!/bin/sh\n" . ($on ? "ln -sf ../$kind-available/\$2$suffix $conf/$kind-enabled/\$2$suffix" : "rm -f $conf/$kind-enabled/\$2$suffix") . "\necho $tool \$2\n");
    }
    file_put_contents("$bin/systemctl", "#!/bin/sh\necho \"systemctl \$*\"\n[ \"\$1\" = is-active ] && echo active\nexit 0\n");
    array_map(fn ($f) => chmod($f, 0o755), glob("$bin/*"));

    $this->conf = $conf;
    $this->apache = new LocalApacheService([PHP_BINARY, 'bin/sys-apache-helper'], ['SYS_APACHE_ROOT' => $conf, 'SYS_APACHE_BIN' => $bin, 'SYS_APACHE_BACKUPS' => "{$this->dir}/backups"]);
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('the helper answers, and lists sites and modules with what is enabled', function () {
    $overview = $this->apache->overview();

    expect($this->apache->status())->toBe(['ready' => true, 'problem' => null])
        ->and($overview['version'])->toBe('Apache/2.4.58 (Ubuntu)')
        ->and(array_column($overview['sites'], 'enabled', 'name'))->toBe(['shop' => true, 'sys' => false])
        ->and(array_column($overview['mods'], 'enabled', 'name'))->toBe(['rewrite' => false])
        ->and($this->apache->read('site', 'shop'))->toContain('ServerName shop.test');
});

test('a change Apache refuses is undone, and every change is logged either way', function () {
    $this->apache->write($this->admin, 'site', 'shop', "<VirtualHost *:80>\nServerName shop2.test\n</VirtualHost>\n");

    expect(fn () => $this->apache->write($this->admin, 'site', 'shop', "<VirtualHost *:80>\nBROKEN\n</VirtualHost>\n"))->toThrow(DomainException::class, 'undone')
        ->and(file_get_contents("{$this->conf}/sites-available/shop.conf"))->toContain('shop2.test')
        ->and(glob("{$this->dir}/backups/*"))->toHaveCount(2)
        ->and(ApacheAdminLog::query()->orderBy('id')->get()->map(fn ($l) => [$l->action, $l->target, $l->ok])->all())->toBe([['write', 'site shop', true], ['write', 'site shop', false]]);
});

test('a new site is built from checked fields, can be enabled, then reloaded', function () {
    $this->apache->createSite($this->admin, ['name' => 'blog.test', 'server_name' => 'Blog.test', 'aliases' => 'www.blog.test', 'document_root' => '/var/www/blog', 'enable' => '1']);
    $this->apache->service($this->admin, 'reload');

    expect(file_get_contents("{$this->conf}/sites-available/blog.test.conf"))->toBe("# Created by sys.\n<VirtualHost *:80>\n    ServerName blog.test\n    ServerAlias www.blog.test\n    DocumentRoot \"/var/www/blog\"\n    ErrorLog \"\${APACHE_LOG_DIR}/blog.test-error.log\"\n    CustomLog \"\${APACHE_LOG_DIR}/blog.test-access.log\" combined\n</VirtualHost>\n")
        ->and(is_link("{$this->conf}/sites-enabled/blog.test.conf"))->toBeTrue()
        ->and(LocalApacheService::siteFile(['name' => 's', 'server_name' => 's.test', 'document_root' => '/w', 'ssl_certificate' => '/c.pem', 'ssl_key' => '/k.pem']))->toContain("<VirtualHost *:443>")->toContain('SSLEngine on');
});

test('nothing unchecked reaches the configuration', function (array $input, string $error) {
    expect(fn () => LocalApacheService::siteFile($input + ['name' => 'x', 'server_name' => 'x.test', 'document_root' => '/var/www/x']))->toThrow(DomainException::class, $error);
})->with([
    'newline in hostname' => [['server_name' => "x.test\nInclude /etc/shadow"], 'hostname'],
    'quote in path' => [['document_root' => '/var/www/x" \nFoo "'], 'full path'],
    'relative path' => [['document_root' => 'www/x'], 'full path'],
    'parent dir' => [['document_root' => '/var/www/../../etc'], 'full path'],
    'bad alias' => [['aliases' => 'ok.test bad;alias'], 'hostname'],
    'bad file name' => [['name' => '../evil'], 'file name'],
]);

test('this app\'s own site can\'t be disabled from here; modules can be; non-admins can\'t change anything', function () {
    $this->apache->toggle($this->admin, 'enable', 'site', 'sys');

    expect(fn () => $this->apache->toggle($this->admin, 'disable', 'site', 'sys'))->toThrow(DomainException::class, 'serves this app');

    $this->apache->toggle($this->admin, 'enable', 'mod', 'rewrite');
    expect(is_link("{$this->conf}/mods-enabled/rewrite.load"))->toBeTrue();

    $user = User::query()->create(['username' => 'bob', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    expect(fn () => $this->apache->service($user, 'restart'))->toThrow(AuthorizationException::class);
});

test('a helper that is missing or refused by sudo shows what to do', function () {
    $missing = new LocalApacheService([PHP_BINARY, '-r', 'fwrite(STDERR, "sudo: a password is required\n"); exit(1);']);

    expect($missing->status()['ready'])->toBeFalse()
        ->and($missing->status()['problem'])->toContain('a password is required')->toContain('install-apache-helper');
});
