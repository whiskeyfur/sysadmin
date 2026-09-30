<?php

use App\Enums\ServerPlatform;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\ApacheAdminLog;
use App\Models\Server;
use App\Models\User;
use App\Services\Fail2banService;
use App\Services\ServerService;
use phpseclib3\Net\SSH2;

beforeEach(function () {
    $this->admin = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    $this->server = (new ServerService($this->cipher))->create($this->admin, ['name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'ops']);
    $this->server->ssh_host_key = 'ssh-ed25519 AAAA';
    $this->server->save();

    // fail2ban-client as sudo would run it: jails and their bans; $denied = no sudo rule.
    $this->ssh = new Fail2banFakeSsh();
    $this->f2b = fn () => new Fail2banService($this->ssh);
});

// A scripted server: `sudo -n fail2ban-client ...` answers from $jails, or sudo refuses.
class Fail2banFakeSsh extends App\Services\SshService
{
    public array $jails = ['sshd' => ['203.0.113.9'], 'apache-auth' => []];

    public bool $denied = false;

    public bool $installed = true;

    /** @var array<string, list<string>> jail => ignoreip */
    public array $ignores = ['sshd' => ['127.0.0.1/8'], 'apache-auth' => ['127.0.0.1/8']];

    public array $commands = [];

    public function __construct()
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

    public function platformOf(SSH2 $ssh): ServerPlatform
    {
        return ServerPlatform::Unix;
    }

    public function exec(SSH2 $ssh, string $command): string
    {
        $this->commands[] = $command;
        $status = fn () => "Status\n|- Number of jail:\t" . count($this->jails) . "\n`- Jail list:\t" . implode(', ', array_keys($this->jails)) . "\n";

        if (str_starts_with($command, 'sh -c ')) { // readBans()
            if (!$this->installed) {
                return "@@none\n";
            }

            if ($this->denied) {
                return "@@denied\n";
            }

            $out = $status();

            foreach ($this->jails as $jail => $ips) {
                $out .= "@@jail $jail\nStatus for the jail: $jail\n`- Actions\n   |- Currently banned:\t" . count($ips) . "\n   `- Banned IP list:\t" . implode(' ', $ips) . "\n";
                $list = $this->ignores[$jail] ?? [];
                $out .= "@@ignore $jail\n" . ($list === [] ? "No IP address/network is ignored\n" : "These IP addresses/networks are ignored:\n" . implode('', array_map(fn ($ip, $i) => ($i === count($list) - 1 ? '`- ' : '|- ') . "$ip\n", $list, array_keys($list))));
            }

            return $out;
        }

        if ($this->denied) {
            throw new ServerConnectionException('The command exited with status 1: sudo: a password is required');
        }

        if (preg_match("/^sudo -n fail2ban-client set '([^']+)' (addignoreip|delignoreip) '([^']+)' 2>&1$/", $command, $m) === 1) {
            $this->ignores[$m[1]] = $m[2] === 'addignoreip' ? [...($this->ignores[$m[1]] ?? []), $m[3]] : array_values(array_diff($this->ignores[$m[1]] ?? [], [$m[3]]));

            return "These IP addresses/networks are ignored:\n";
        }

        if (preg_match("/^sudo -n fail2ban-client set '([^']+)' (banip|unbanip) '([^']+)' 2>&1$/", $command, $m) === 1) {
            $this->jails[$m[1]] = $m[2] === 'banip' ? [...$this->jails[$m[1]], $m[3]] : array_values(array_diff($this->jails[$m[1]], [$m[3]]));

            return "1\n";
        }

        if ($command === 'sudo -n fail2ban-client status 2>&1') {
            return $status();
        }

        throw new ServerConnectionException('The command exited with status 127: not found');
    }
}

test('the jails and who they have banned are read into the server; banning and unbanning change both', function () {
    ($this->f2b)()->readBans(new SSH2('localhost'), $this->server);

    expect($this->server->fresh()->fail2ban_bans)->toBe(['sshd' => ['203.0.113.9'], 'apache-auth' => []])
        ->and($this->server->fresh()->bannedIn('203.0.113.9'))->toBe(['sshd'])
        ->and(($this->f2b)()->jails($this->admin, $this->server))->toBe(['sshd', 'apache-auth']);

    ($this->f2b)()->change($this->admin, $this->server, 'ban', '198.51.100.4', 'apache-auth', '192.0.2.1');
    ($this->f2b)()->change($this->admin, $this->server, 'unban', '203.0.113.9', 'sshd', '192.0.2.1');

    expect($this->ssh->jails)->toBe(['sshd' => [], 'apache-auth' => ['198.51.100.4']])
        ->and($this->server->fresh()->bannedIn('198.51.100.4'))->toBe(['apache-auth'])
        ->and($this->server->fresh()->bannedIn('203.0.113.9'))->toBe([])
        ->and(ApacheAdminLog::query()->orderBy('id')->get()->map(fn ($l) => "{$l->action} {$l->target} " . ($l->ok ? 'ok' : 'no'))->all())
        ->toBe(['fail2ban ban 198.51.100.4 in apache-auth ok', 'fail2ban unban 203.0.113.9 in sshd ok']);
});

test('refused: your own address, the server itself, bad input, unknown jails, non-admins', function (string $action, string $ip, string $jail, string $message) {
    expect(fn () => ($this->f2b)()->change($this->admin, $this->server, $action, $ip, $jail, '192.0.2.1'))->toThrow(DomainException::class, $message);
    expect($this->ssh->jails['sshd'])->toBe(['203.0.113.9']);
})->with([
    ['ban', '192.0.2.1', 'sshd', 'your own address'],
    ['ban', '127.0.0.1', 'sshd', 'the server itself'],
    ['ban', '::1', 'sshd', 'the server itself'],
    ['ban', 'not-an-ip', 'sshd', "isn't an IP address"],
    ['ban', '198.51.100.4', 'sshd; rm -rf /', 'Choose one of'],
    ['ban', '198.51.100.4', 'nginx', 'no fail2ban jail called nginx'],
    ['delete', '198.51.100.4', 'sshd', 'Choose ban, unban, protect'],
]);

test('only admins, and only over a trusted SSH login', function () {
    expect(fn () => ($this->f2b)()->change(new User(['role' => User::ROLE_USER]), $this->server, 'ban', '198.51.100.4', 'sshd'))->toThrow(AuthorizationException::class);

    $this->server->ssh_host_key = null;
    $this->server->save();
    expect(fn () => ($this->f2b)()->jails($this->admin, $this->server->fresh()))->toThrow(DomainException::class, "SSH isn't set up");
});

test('without a sudo rule or fail2ban, nothing is tried and the page says why', function () {
    $this->ssh->denied = true;
    ($this->f2b)()->readBans(new SSH2('localhost'), $this->server);

    expect($this->server->fresh()->fail2ban_bans)->toBeNull()
        ->and($this->server->fresh()->fail2ban_message)->toContain('ops ALL=(root) NOPASSWD: /usr/bin/fail2ban-client')
        // The read asks sudo -l first: no failed sudo attempt.
        ->and($this->ssh->commands[0])->toContain('sudo -n -l fail2ban-client status')
        ->and(fn () => ($this->f2b)()->change($this->admin, $this->server, 'ban', '198.51.100.4', 'sshd'))->toThrow(DomainException::class, 'may not run fail2ban-client with sudo');

    $this->ssh->denied = false;
    $this->ssh->installed = false;
    ($this->f2b)()->readBans(new SSH2('localhost'), $this->server);
    expect($this->server->fresh()->fail2ban_message)->toBe("fail2ban-client isn't installed.");
});

test('an address is protected in every jail; it can\'t be banned until the protection is removed, and a banned one can\'t be protected', function () {
    $f2b = ($this->f2b)();

    // Banned in sshd: unban first.
    expect(fn () => $f2b->change($this->admin, $this->server, 'protect', '203.0.113.9'))->toThrow(DomainException::class, 'unban it before protecting it');

    expect($f2b->change($this->admin, $this->server, 'protect', '198.51.100.4'))->toContain('protected from banning')
        ->and($this->ssh->ignores)->toBe(['sshd' => ['127.0.0.1/8', '198.51.100.4'], 'apache-auth' => ['127.0.0.1/8', '198.51.100.4']])
        ->and($f2b->isProtected($this->server, '198.51.100.4'))->toBeTrue()
        ->and(fn () => $f2b->change($this->admin, $this->server, 'ban', '198.51.100.4', 'sshd'))->toThrow(DomainException::class, 'remove its protection first')
        ->and($this->ssh->jails['sshd'])->toBe(['203.0.113.9']);

    $f2b->change($this->admin, $this->server, 'unprotect', '198.51.100.4');
    expect($this->ssh->ignores['sshd'])->toBe(['127.0.0.1/8'])
        ->and($f2b->isProtected($this->server, '198.51.100.4'))->toBeFalse()
        ->and(fn () => $f2b->change($this->admin, $this->server, 'unprotect', '198.51.100.4'))->toThrow(DomainException::class, "isn't protected");

    $f2b->change($this->admin, $this->server, 'ban', '198.51.100.4', 'sshd', '192.0.2.1');
    expect($this->ssh->jails['sshd'])->toContain('198.51.100.4')
        ->and(ApacheAdminLog::query()->pluck('action')->all())->toContain('fail2ban protect')->toContain('fail2ban unprotect');
});

test('protection lost with a fail2ban restart is put back when the bans are read; nothing runs when it\'s all there', function () {
    ($this->f2b)()->change($this->admin, $this->server, 'protect', '198.51.100.4');
    $this->ssh->ignores = ['sshd' => ['127.0.0.1/8'], 'apache-auth' => ['127.0.0.1/8', '198.51.100.4']]; // sshd forgot it
    $this->ssh->commands = [];

    ($this->f2b)()->readBans(new SSH2('localhost'), $this->server);
    expect($this->ssh->ignores['sshd'])->toBe(['127.0.0.1/8', '198.51.100.4'])
        ->and(count($this->ssh->commands))->toBe(2); // the read, and one addignoreip

    $this->ssh->commands = [];
    ($this->f2b)()->readBans(new SSH2('localhost'), $this->server);
    expect(count($this->ssh->commands))->toBe(1)
        ->and($this->server->fresh()->fail2ban_bans)->toBe(['sshd' => ['203.0.113.9'], 'apache-auth' => []]);
});
