<?php

use App\DTOs\HostKey;
use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SshKeyService;
use App\Services\SshService;
use App\Services\SshSetupService;
use phpseclib3\Net\SSH2;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->keys = new SshKeyService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->hostKey = new HostKey('ssh-ed25519', base64_encode(random_bytes(51)));

    // A scripted stand-in for SshService that records every login attempt.
    $this->ssh = new class ($this->hostKey) extends SshService {
        /** @var list<bool> */
        public array $keyResults = [];
        public bool $passwordWorks = true;
        public bool $installWorks = true;
        public int $passwordAttempts = 0;
        public int $keyAttempts = 0;
        public array $commands = [];

        public function __construct(private HostKey $key)
        {
        }

        public function presentedHostKey(Server $server): HostKey
        {
            return $this->key;
        }

        public function connectWithKey(Server $server): SSH2
        {
            $this->keyAttempts++;

            if (!(array_shift($this->keyResults) ?? false)) {
                throw new ServerConnectionException('key refused');
            }

            return $this->fakeConnection();
        }

        public function connectWithPassword(Server $server, string $password): SSH2
        {
            // Count every call, so a missing guard in SshSetupService shows up.
            $this->passwordAttempts++;

            if (!$server->ssh_password_allowed) {
                throw new ServerConnectionException('not allowed');
            }

            if (!$this->passwordWorks) {
                throw new ServerConnectionException('password refused');
            }

            return $this->fakeConnection();
        }

        public function exec(SSH2 $ssh, string $command): string
        {
            $this->commands[] = $command;

            if (!$this->installWorks) {
                throw new ServerConnectionException('permission denied');
            }

            return '';
        }

        private function fakeConnection(): SSH2
        {
            return new class ('localhost') extends SSH2 {
                public function disconnect()
                {
                }
            };
        }
    };

    $this->setup = new SshSetupService($this->servers, $this->ssh, $this->keys);
    $this->makeServer = fn (bool $passwordAllowed) => $this->servers->create($this->admin, [
        'name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'deploy',
        'ssh_password_allowed' => $passwordAllowed ? '1' : '',
    ]);
});

test('password login is off by default', function () {
    expect(($this->makeServer)(false)->ssh_password_allowed)->toBeFalse();
});

test('the host key must match the fingerprint the admin checked', function () {
    $server = ($this->makeServer)(true);
    $result = $this->setup->setUp($this->admin, $server, 'SHA256:somethingelse', 'pw');

    expect($result->ok)->toBeFalse()
        ->and($server->fresh()->ssh_host_key)->toBeNull()
        ->and($this->ssh->keyAttempts + $this->ssh->passwordAttempts)->toBe(0);
});

test('when the key already works, no password is used or stored', function () {
    $server = ($this->makeServer)(true);
    $this->ssh->keyResults = [true];
    $result = $this->setup->setUp($this->admin, $server, $this->hostKey->fingerprint(), 'pw');

    expect($result->ok)->toBeTrue()
        ->and($this->ssh->passwordAttempts)->toBe(0)
        ->and($server->fresh()->ssh_auth)->toBe(Server::SSH_AUTH_KEY)
        ->and($server->fresh()->ssh_password)->toBeNull()
        ->and($server->fresh()->ssh_host_key)->toBe($this->hostKey->toString());
});

test('the password installs the key; once key login works it is not stored', function () {
    $server = ($this->makeServer)(true);
    $this->ssh->keyResults = [false, true];
    $result = $this->setup->setUp($this->admin, $server, $this->hostKey->fingerprint(), 'pw');

    expect($result->ok)->toBeTrue()
        ->and($this->ssh->passwordAttempts)->toBe(1)
        ->and($this->ssh->commands[0])->toContain($this->keys->publicKey())
        ->and($server->fresh()->ssh_auth)->toBe(Server::SSH_AUTH_KEY)
        ->and($server->fresh()->ssh_password)->toBeNull();
});

test('a server that refuses key login falls back to the stored, encrypted password', function () {
    $server = ($this->makeServer)(true);
    $this->ssh->keyResults = [false, false];
    $result = $this->setup->setUp($this->admin, $server, $this->hostKey->fingerprint(), 'pw');
    $server = $server->fresh();

    expect($result->ok)->toBeTrue()
        ->and($server->ssh_auth)->toBe(Server::SSH_AUTH_PASSWORD)
        ->and($server->ssh_password)->not->toContain('pw')
        ->and($this->servers->sshPassword($server))->toBe('pw');
});

test('with password login not allowed, no password is ever tried or stored', function () {
    $server = ($this->makeServer)(false);
    $this->ssh->keyResults = [false];
    $result = $this->setup->setUp($this->admin, $server, $this->hostKey->fingerprint(), 'pw');

    expect($result->ok)->toBeFalse()
        ->and($this->ssh->passwordAttempts)->toBe(0)
        ->and($this->ssh->keyAttempts)->toBe(1)
        ->and($server->fresh()->ssh_password)->toBeNull();
});

test('a refused password or failed install stops without storing anything', function (bool $passwordWorks, bool $installWorks) {
    $server = ($this->makeServer)(true);
    $this->ssh->keyResults = [false];
    $this->ssh->passwordWorks = $passwordWorks;
    $this->ssh->installWorks = $installWorks;
    $result = $this->setup->setUp($this->admin, $server, $this->hostKey->fingerprint(), 'pw');

    expect($result->ok)->toBeFalse()
        ->and($this->ssh->passwordAttempts)->toBe(1)
        ->and($server->fresh()->ssh_password)->toBeNull();
})->with([[false, true], [true, false]]);

test('turning password login off forgets a stored password', function () {
    $server = ($this->makeServer)(true);
    $this->servers->usePasswordAuth($server, 'pw');
    $this->servers->update($this->admin, $server, [
        'name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'deploy', 'ssh_password_allowed' => '',
    ]);

    expect($server->fresh()->ssh_password)->toBeNull()
        ->and($server->fresh()->ssh_auth)->toBe(Server::SSH_AUTH_KEY);
});

test('changing the SSH user forgets a stored password', function () {
    $server = ($this->makeServer)(true);
    $this->servers->usePasswordAuth($server, 'pw');
    $this->servers->update($this->admin, $server, [
        'name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_username' => 'other', 'ssh_password_allowed' => '1',
    ]);

    expect($server->fresh()->ssh_password)->toBeNull();
});

test('the install command is idempotent, fixes permissions and starts on a new line', function () {
    $home = sys_get_temp_dir() . '/sys-home-' . bin2hex(random_bytes(4));
    mkdir("$home/.ssh", 0o755, true);
    file_put_contents("$home/.ssh/authorized_keys", 'ssh-ed25519 AAAAexisting other@host'); // no trailing newline

    $key = $this->keys->publicKey();
    $command = $this->setup->installCommand($key);

    foreach ([1, 2] as $run) {
        exec('HOME=' . escapeshellarg($home) . ' ' . $command, $output, $status);
        expect($status)->toBe(0);
    }

    $lines = file("$home/.ssh/authorized_keys", FILE_IGNORE_NEW_LINES);

    expect($lines)->toBe(['ssh-ed25519 AAAAexisting other@host', $key])
        ->and(substr(sprintf('%o', fileperms("$home/.ssh")), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms("$home/.ssh/authorized_keys")), -3))->toBe('600');

    array_map('unlink', glob("$home/.ssh/*"));
    rmdir("$home/.ssh");
    rmdir($home);
});

test('SshService refuses a password login before connecting when it is not allowed', function () {
    $server = ($this->makeServer)(false);
    $server->hostname = '192.0.2.1'; // TEST-NET: must never be contacted

    $started = microtime(true);

    expect(fn () => (new SshService($this->keys, $this->servers))->connectWithPassword($server, 'pw'))
        ->toThrow(ServerConnectionException::class, 'no password was tried')
        ->and(microtime(true) - $started)->toBeLessThan(1);
});

test('a server with SSH turned off is never contacted', function () {
    $server = $this->servers->create($this->admin, ['name' => 'site', 'hostname' => '192.0.2.1', 'ssh_enabled' => '', 'ssl_enabled' => '1']);
    $real = new SshService($this->keys, $this->servers);
    $started = microtime(true);

    expect(fn () => $real->presentedHostKey($server))->toThrow(ServerConnectionException::class, 'turned off')
        ->and(fn () => $real->connectWithKey($server))->toThrow(ServerConnectionException::class, 'turned off')
        ->and($this->setup->setUp($this->admin, $server, null, null)->ok)->toBeFalse()
        ->and(microtime(true) - $started)->toBeLessThan(1);
});
