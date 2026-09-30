<?php

use App\Exceptions\ServerConnectionException;
use App\Models\ApacheAdminLog;
use App\Models\ApacheVhost;
use App\Models\Server;
use App\Models\User;
use App\Services\LocalApacheService;
use App\Services\RemoteApacheService;
use App\Services\RewriteEditorService;
use App\Services\ServerService;
use App\Services\SshService;
use App\Services\VhostEditorService;
use Carbon\Carbon;
use phpseclib3\Net\SSH2;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/vhost-editor-' . bin2hex(random_bytes(4));
    $root = "{$this->dir}/apache2";

    foreach (["$root/sites-available", "$root/sites-enabled", "$root/conf-available", "$root/conf-enabled", "$root/mods-available", "$root/mods-enabled", "{$this->dir}/www", "{$this->dir}/bin", "{$this->dir}/home"] as $dir) {
        mkdir($dir, 0o755, true);
    }

    $this->site = "<VirtualHost *:80>\n    ServerName shop.test\n    ServerAlias www.shop.test\n    DocumentRoot {$this->dir}/www\n    # logs\n    ErrorLog \${APACHE_LOG_DIR}/shop-error.log\n    CustomLog \${APACHE_LOG_DIR}/shop-access.log combined\n</VirtualHost>\n\n<VirtualHost *:443>\n    ServerName shop.test\n    DocumentRoot {$this->dir}/www\n    Include $root/conf-available/ssl-options.conf\n    <IfModule mod_ssl.c>\n        SSLEngine on\n        SSLCertificateFile /etc/ssl/shop.pem\n        SSLCertificateKeyFile /etc/ssl/shop.key\n    </IfModule>\n</VirtualHost>\n";
    file_put_contents("$root/apache2.conf", "ServerRoot $root\nIncludeOptional sites-enabled/*.conf\n");
    file_put_contents("$root/envvars", "export APACHE_LOG_DIR=/var/log/apache2\$SUFFIX\n");
    file_put_contents("$root/conf-available/ssl-options.conf", "# shared\n# options\n# for\n# every\n# ssl\n# host\nSSLEngine on\nSSLProtocol all\n");
    file_put_contents("$root/sites-available/shop.conf", $this->site);
    symlink('../sites-available/shop.conf', "$root/sites-enabled/shop.conf");

    // Stand-ins: apachectl fails the test on "BROKEN"; sudo allows only what's in $dir/sudo-rules (one command prefix a line).
    $bin = "{$this->dir}/bin";
    file_put_contents("$bin/apachectl", "#!/bin/sh\nif grep -Rqs BROKEN {$this->dir}/remote $root/sites-enabled/; then echo 'AH00526: Syntax error: BROKEN'; exit 1; fi\necho 'Syntax OK'\n");
    file_put_contents("$bin/apache2ctl", file_get_contents("$bin/apachectl"));
    file_put_contents("$bin/systemctl", "#!/bin/sh\nif [ \"\$1\" = list-unit-files ]; then echo 'apache2.service enabled'; exit 0; fi\necho \"systemctl \$*\"\n");
    file_put_contents("$bin/sudo", <<<SH
        #!/bin/sh
        [ "\$1" = -n ] || { echo 'expected -n' >&2; exit 2; }
        shift
        list=0
        [ "\$1" = -l ] && { list=1; shift; }
        cmd="\$*"
        allowed=0
        while IFS= read -r rule; do
            [ -n "\$rule" ] || continue
            case "\$cmd" in "\$rule"*) allowed=1 ;; esac
        done < {$this->dir}/sudo-rules
        [ \$allowed = 1 ] || { echo 'sudo: a password is required' >&2; exit 1; }
        [ \$list = 1 ] && { echo "\$cmd"; exit 0; }
        # As root could: tee a read-only file (then put its mode back).
        if [ "\$1" = tee ]; then
            mode=\$(stat -c %a "\$2"); chmod u+w "\$2"; tee "\$2"; status=\$?; chmod "\$mode" "\$2"; exit \$status
        fi
        exec "\$@"
        SH);
    file_put_contents("$bin/apache2ctl", file_get_contents("$bin/apachectl"));
    array_map(fn ($f) => chmod($f, 0o755), glob("$bin/*"));
    file_put_contents("{$this->dir}/sudo-rules", '');

    mkdir("{$this->dir}/remote");
    $this->remoteFile = "{$this->dir}/remote/shop.conf";
    file_put_contents($this->remoteFile, $this->site);

    // An SshService that runs commands here, with the stand-ins first on PATH and HOME in the scratch dir.
    $this->ssh = new class ($bin, "{$this->dir}/home") extends SshService {
        public array $commands = [];

        public function __construct(private readonly string $bin, private readonly string $home)
        {
        }

        public function connect(Server $server): SSH2
        {
            return new class ('localhost') extends SSH2 {
                public function disconnect()
                {
                }
            };
        }

        public function exec(SSH2 $ssh, string $command): string
        {
            $this->commands[] = $command;
            exec('env PATH=' . escapeshellarg("{$this->bin}:/usr/bin:/bin") . ' HOME=' . escapeshellarg($this->home) . ' sh -c ' . escapeshellarg($command) . ' 2>&1', $out, $code);
            $output = implode("\n", $out) . ($out === [] ? '' : "\n");

            if ($code !== 0) {
                throw new ServerConnectionException("The command exited with status $code: $output");
            }

            return $output;
        }
    };
    $this->remote = new RemoteApacheService($this->ssh);
    $apache = new LocalApacheService([PHP_BINARY, 'bin/sys-apache-helper'], ['SYS_APACHE_ROOT' => $root, 'SYS_APACHE_BIN' => $bin, 'SYS_APACHE_BACKUPS' => "{$this->dir}/backups"]);
    $this->editor = new VhostEditorService(new RewriteEditorService($apache, $root), $this->remote, $apache);
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->server = (new ServerService($this->cipher))->create($this->admin, ['name' => 'web1', 'hostname' => 'web1.test', 'ssh_port' => 22, 'ssh_username' => 'deploy', 'apache_enabled' => '1']);
    $this->server->ssh_host_key = 'ssh-ed25519 AAAA';
    $this->server->save();
    $this->vhost = ApacheVhost::query()->create(['server_id' => $this->server->id, 'name' => 'shop.test', 'address' => '*:443', 'port' => 443, 'ssl' => true, 'config_file' => $this->remoteFile, 'first_seen_at' => Carbon::now(), 'last_seen_at' => Carbon::now()]);
    $this->rules = fn (string ...$rules) => file_put_contents("{$this->dir}/sudo-rules", implode("\n", $rules) . "\n");
    $this->root = $root;
});

afterEach(function () {
    exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
});

test('saving the fields unchanged leaves the file as it was: ${VARIABLES} stay, included files\' lines aren\'t taken as the block\'s', function () {
    foreach ($this->editor->localVhosts() as $vhost) {
        $loaded = $this->editor->load($vhost['id']);

        expect($this->editor->withFields($loaded, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '') : $v, $loaded['fields'])))->toBe($loaded['text']);
    }

    expect($this->editor->load(collect($this->editor->localVhosts())->first()['id'])['fields']['error_log'])->toBe('${APACHE_LOG_DIR}/shop-error.log');
});

test('the fields change only their own lines: new values replace, empty ones go, missing ones are added before </VirtualHost>', function () {
    $loaded = $this->editor->load(collect($this->editor->localVhosts())->first()['id']);

    expect($loaded['fields'])->toMatchArray(['address' => '*:80', 'server_name' => 'shop.test', 'aliases' => 'www.shop.test', 'error_log' => '${APACHE_LOG_DIR}/shop-error.log', 'ssl_engine' => false]);

    $text = $this->editor->withFields($loaded, ['address' => '*:8080', 'server_name' => 'shop.test', 'aliases' => 'www.shop.test shop2.test', 'document_root' => "{$this->dir}/www",
        'error_log' => '', 'access_log' => '/var/log/shop.log', 'https_redirect' => 'https://shop.test/']);

    expect($text)->toStartWith("<VirtualHost *:8080>\n    ServerName shop.test\n    ServerAlias www.shop.test shop2.test\n    DocumentRoot {$this->dir}/www\n    # logs\n    CustomLog /var/log/shop.log combined\n    Redirect permanent / https://shop.test/\n</VirtualHost>\n\n<VirtualHost *:443>")
        ->and(substr($text, strpos($text, '<VirtualHost *:443>')))->toBe(substr($this->site, strpos($this->site, '<VirtualHost *:443>')));
});

test('fields inside <IfModule> are found and changed in place; SSL needs its files; bad values are refused', function () {
    $loaded = $this->editor->load(collect($this->editor->localVhosts())->last()['id']);
    $fields = $loaded['fields'];

    expect($fields['ssl_engine'])->toBeTrue()->and($fields['ssl_certificate'])->toBe('/etc/ssl/shop.pem');

    $text = $this->editor->withFields($loaded, ['ssl_certificate' => '/etc/ssl/new.pem', 'ssl_engine' => '1'] + $fields);
    expect($text)->toContain("    Include {$this->root}/conf-available/ssl-options.conf\n    <IfModule mod_ssl.c>\n        SSLEngine on\n        SSLCertificateFile /etc/ssl/new.pem\n");

    expect(fn () => $this->editor->withFields($loaded, ['ssl_key' => ''] + $fields))->toThrow(DomainException::class, 'certificate and key')
        ->and(fn () => $this->editor->withFields($loaded, ['server_name' => "x\nInclude /etc/shadow"] + $fields))->toThrow(DomainException::class, 'hostname')
        ->and(fn () => $this->editor->withFields($loaded, ['document_root' => '/var/www" Foo "'] + $fields))->toThrow(DomainException::class, 'full path')
        ->and(fn () => $this->editor->withFields($loaded, ['address' => '*:443>'] + $fields))->toThrow(DomainException::class, 'address');
});

test('the definition text replaces only its block, must be one <VirtualHost>, and saving here goes through the helper', function () {
    $loaded = $this->editor->load(collect($this->editor->localVhosts())->first()['id']);
    $text = $this->editor->withBlock($loaded, "<VirtualHost *:80>\n    ServerName other.test\n</VirtualHost>");

    expect($text)->toStartWith("<VirtualHost *:80>\n    ServerName other.test\n</VirtualHost>\n\n<VirtualHost *:443>")
        ->and(fn () => $this->editor->withBlock($loaded, "<VirtualHost *:80>\n</VirtualHost>\n<VirtualHost *:81>\n</VirtualHost>"))->toThrow(DomainException::class, 'exactly one')
        ->and(fn () => $this->editor->withBlock($loaded, "<VirtualHost *:80>\nServerName x"))->toThrow(DomainException::class, 'exactly one');

    $this->editor->save($this->admin, $loaded, $loaded['hash'], $text);
    expect(file_get_contents("{$this->root}/sites-available/shop.conf"))->toStartWith("<VirtualHost *:80>\n    ServerName other.test\n");

    expect(fn () => $this->editor->save($this->admin, $loaded, $loaded['hash'], $text))->toThrow(DomainException::class, 'changed since');
});

test('a remote server: what sudo allows is asked without running anything', function () {
    ($this->rules)("{$this->dir}/bin/apachectl configtest", 'systemctl reload apache2');
    $caps = $this->remote->capabilities($this->server);

    expect($caps)->toMatchArray(['ctl' => "{$this->dir}/bin/apache2ctl", 'service' => 'apache2', 'reload' => true, 'restart' => false, 'sudo' => true])
        ->and($caps['test'])->toBeNull(); // the rule is for apachectl, and apache2ctl was found first

    ($this->rules)("{$this->dir}/bin/apache2ctl configtest", 'systemctl reload apache2', 'systemctl restart apache2');
    expect($this->remote->capabilities($this->server))->toMatchArray(['test' => 'configtest', 'restart' => true])
        ->and(collect($this->ssh->commands)->filter(fn ($c) => str_contains($c, 'sudo -n ') && !str_contains($c, 'sudo -n -l'))->all())->toBe([]);
});

test('a remote edit: written with sudo tee when the file isn\'t writable, configtested, put back when refused, logged with the old text', function () {
    ($this->rules)("{$this->dir}/bin/apache2ctl configtest", "tee {$this->remoteFile}", 'systemctl reload apache2');
    chmod($this->remoteFile, 0o444);

    $loaded = $this->editor->load("vhost:{$this->vhost->id}");
    expect($loaded['write'])->toBe('sudo')->and($loaded['fields']['ssl_certificate'])->toBe('/etc/ssl/shop.pem');

    $text = $this->editor->withFields($loaded, ['ssl_certificate' => "/etc/ssl/it's \"new\".pem"] + $loaded['fields']);
})->throws(DomainException::class, 'full path');

test('a remote edit round trip, and an undo when Apache refuses', function () {
    ($this->rules)("{$this->dir}/bin/apache2ctl configtest", "tee {$this->remoteFile}", 'systemctl reload apache2');
    chmod($this->remoteFile, 0o444);

    $loaded = $this->editor->load("vhost:{$this->vhost->id}");
    $big = str_repeat("    # padding for a multi-chunk upload, with 'quotes' and \"double\" and \$vars\n", 3000);
    $text = str_replace('</VirtualHost>', $big . '</VirtualHost>', $this->editor->withFields($loaded, ['ssl_certificate' => '/etc/ssl/new.pem'] + $loaded['fields']));
    $this->editor->save($this->admin, $loaded, $loaded['hash'], $text);

    expect(file_get_contents($this->remoteFile))->toBe($text)
        ->and(fileperms($this->remoteFile) & 0o777)->toBe(0o444)
        ->and(glob("{$this->dir}/home/.sys-stage-*"))->toBe([])
        ->and(ApacheAdminLog::query()->latest('id')->first()->previous)->toBe($this->site)
        ->and($this->server->fresh()->apache_scanned_at)->toBeNull();

    $loaded = $this->editor->load("vhost:{$this->vhost->id}");
    expect(fn () => $this->editor->save($this->admin, $loaded, $loaded['hash'], str_replace('ServerName shop.test', "BROKEN\nServerName shop.test", $loaded['text'])))
        ->toThrow(DomainException::class, 'old file was put back')
        ->and(file_get_contents($this->remoteFile))->toBe($text);
});

test('without the rules it needs, a remote server says what\'s missing and nothing is written or restarted', function () {
    ($this->rules)("{$this->dir}/bin/apache2ctl configtest");
    chmod($this->remoteFile, 0o444);
    $loaded = $this->editor->load("vhost:{$this->vhost->id}");

    expect($loaded['write'])->toBeNull()
        ->and(fn () => $this->editor->save($this->admin, $loaded, $loaded['hash'], $loaded['text'] . "\n"))->toThrow(DomainException::class, 'tee')
        ->and(fn () => $this->remote->service($this->admin, $this->server, 'restart'))->toThrow(DomainException::class, 'No sudo rule lets the SSH user restart')
        ->and($this->remote->service($this->admin, $this->server, 'test')['output'])->toContain('Syntax OK');

    ($this->rules)('');
    expect(fn () => $this->remote->service($this->admin, $this->server, 'reload'))->toThrow(DomainException::class, 'configuration test');
});
