<?php

use App\Models\User;
use App\Services\ApacheConfigService;
use App\Services\LocalApacheService;
use App\Services\RewriteEditorService;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/apache-config-' . bin2hex(random_bytes(4));
    $root = "{$this->dir}/apache2";

    foreach (["$root/sites-available", "$root/sites-enabled", "$root/conf-available", "$root/conf-enabled", "$root/mods-available", "$root/mods-enabled", "{$this->dir}/bin"] as $dir) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents("$root/envvars", "export APACHE_LOG_DIR=/var/log/apache2\n");
    file_put_contents("$root/apache2.conf", "# main\nServerRoot $root\n\nKeepAliveTimeout 5\n<IfModule mod_nothere.c>\n    Include conf-available/never.conf\n</IfModule>\nIncludeOptional mods-enabled/*.load\nIncludeOptional mods-enabled/*.conf\nIncludeOptional conf-enabled/*.conf\nIncludeOptional conf-enabled/*.conf\nIncludeOptional sites-enabled/*.conf\n");
    file_put_contents("$root/mods-available/dir.load", "LoadModule dir_module mod_dir.so\n");
    file_put_contents("$root/mods-available/dir.conf", "DirectoryIndex index.html\n");
    file_put_contents("$root/mods-available/status.load", "LoadModule status_module mod_status.so\n");
    symlink('../mods-available/dir.load', "$root/mods-enabled/dir.load");
    symlink('../mods-available/dir.conf', "$root/mods-enabled/dir.conf");
    file_put_contents("$root/conf-available/security.conf", "ServerSignature On\n");
    file_put_contents("$root/conf-available/never.conf", "Never here\n");
    symlink('../conf-available/security.conf', "$root/conf-enabled/security.conf");
    file_put_contents("$root/sites-available/shop.conf", "<VirtualHost *:80>\n    ServerName shop.test\n    ErrorLog \${APACHE_LOG_DIR}/shop.log\n</VirtualHost>\n");
    file_put_contents("$root/sites-available/off.conf", "<VirtualHost *:81>\n</VirtualHost>\n");
    symlink('../sites-available/shop.conf', "$root/sites-enabled/shop.conf");
    file_put_contents("{$this->dir}/bin/apache2ctl", "#!/bin/sh\n[ \"\$1\" = configtest ] && { grep -Rqs BROKEN $root/apache2.conf $root/sites-enabled/ && { echo 'Syntax error' >&2; exit 1; }; echo 'Syntax OK' >&2; }\nexit 0\n");
    chmod("{$this->dir}/bin/apache2ctl", 0o755);

    $this->root = $root;
    $apache = new LocalApacheService([PHP_BINARY, 'bin/sys-apache-helper'], ['SYS_APACHE_ROOT' => $root, 'SYS_APACHE_BIN' => "{$this->dir}/bin", 'SYS_APACHE_BACKUPS' => "{$this->dir}/backups"]);
    $this->service = fn () => new ApacheConfigService(new RewriteEditorService($apache, $root), $apache, $root);
    $this->overview = $apache->overview();
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('the configuration reads in Apache\'s order, includes opened in place, with what doesn\'t apply and what isn\'t enabled', function () {
    $rows = ($this->service)()->rows($this->overview);
    $outline = array_map(fn ($r) => match ($r['type']) {
        'file' => str_repeat('  ', $r['depth']) . '[' . basename(dirname($r['file'])) . '/' . basename($r['file']) . ($r['disable'] ? ' disable ' . $r['disable']['kind'] : '') . ']',
        'line' => str_repeat('  ', $r['depth']) . $r['line'] . ($r['active'] ? '' : ' (off)') . ($r['editable'] ? ' *' : ''),
        'note' => str_repeat('  ', $r['depth']) . 'note: ' . $r['text'],
        'disabled' => str_repeat('  ', $r['depth']) . 'not enabled ' . $r['kind'] . ': ' . implode(',', $r['items']),
        'end' => null,
    }, $rows);

    expect(array_values(array_filter($outline)))->toBe([
        '[apache2/apache2.conf]', '1', '2 *', '3', '4 *', '5 (off)', '6 (off) *', '  note: Not followed: this Include is inside a section that doesn\'t apply.', '7 (off)',
        '8 *', '  [mods-enabled/dir.load disable mod]', '  1', '  not enabled mod: status',
        '9 *', '  [mods-enabled/dir.conf]', '  1',
        '10 *', '  [conf-enabled/security.conf disable conf]', '  1 *', '  not enabled conf: never',
        '11 *', '  note: conf-enabled/security.conf was read above; Apache doesn\'t read it again.', '  not enabled conf: never',
        '12 *', '  [sites-enabled/shop.conf disable site]', '  1', '  2 *', '  3 *', '  4', '  not enabled site: off',
    ]);
});

test('values are saved in place anywhere in writable files (inside a virtual host too), as written', function () {
    $service = ($this->service)();
    $saved = $service->save($this->admin, [
        "{$this->root}/apache2.conf" => [4 => '10'],
        "{$this->root}/sites-enabled/shop.conf" => [2 => 'www.shop.test', 3 => '${APACHE_LOG_DIR}/shop.log'],
    ], [
        "{$this->root}/apache2.conf" => hash_file('sha256', "{$this->root}/apache2.conf"),
        "{$this->root}/sites-enabled/shop.conf" => hash_file('sha256', "{$this->root}/sites-available/shop.conf"),
    ]);

    expect($saved)->toBe(['apache2.conf', 'sites-enabled/shop.conf'])
        ->and(file_get_contents("{$this->root}/apache2.conf"))->toContain("KeepAliveTimeout 10\n")
        ->and(file_get_contents("{$this->root}/sites-available/shop.conf"))->toBe("<VirtualHost *:80>\n    ServerName www.shop.test\n    ErrorLog \${APACHE_LOG_DIR}/shop.log\n</VirtualHost>\n");
});

test('module files, stale pages, bad values and values Apache refuses aren\'t saved', function () {
    $hash = fn (string $f) => hash_file('sha256', $f);
    $main = "{$this->root}/apache2.conf";
    $save = fn (array $changes, array $hashes) => ($this->service)()->save($this->admin, $changes, $hashes);

    expect(fn () => $save(["{$this->root}/mods-enabled/dir.conf" => [1 => 'index.php']], []))->toThrow(DomainException::class, "can't be edited")
        ->and(fn () => $save([$main => [4 => '']], [$main => $hash($main)]))->toThrow(DomainException::class, 'needs a value')
        ->and(fn () => $save([$main => [4 => "1\nInclude /etc/shadow"]], [$main => $hash($main)]))->toThrow(DomainException::class, 'needs a value')
        ->and(fn () => $save([$main => [4 => '7']], [$main => 'stale']))->toThrow(DomainException::class, 'changed since')
        ->and(fn () => $save([$main => [4 => 'BROKEN']], [$main => $hash($main)]))->toThrow(DomainException::class, 'undone')
        ->and(fn () => $save(['/etc/passwd' => [1 => 'x']], []))->toThrow(DomainException::class, "isn't part of the configuration")
        ->and(file_get_contents($main))->toContain('KeepAliveTimeout 5');
});
