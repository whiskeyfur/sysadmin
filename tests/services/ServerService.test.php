<?php

use App\DTOs\HostKey;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->user = new User(['username' => 'alice', 'role' => User::ROLE_USER]);
    $this->input = [
        'name' => 'web-1', 'hostname' => 'Web1.Example.com', 'ssh_port' => '2222', 'ssh_username' => 'monitor',
        'mysql_enabled' => '1', 'mysql_host' => '', 'mysql_port' => '3306', 'mysql_username' => 'sys_monitor', 'mysql_password' => 's3cret!',
    ];
});

test('admins create servers; the MySQL password is encrypted and hidden', function () {
    $server = $this->servers->create($this->admin, $this->input);

    expect($server->hostname)->toBe('web1.example.com')
        ->and($server->ssh_port)->toBe(2222)
        ->and($server->mysqlHost())->toBe('web1.example.com')
        ->and($server->mysql_password)->not->toContain('s3cret')
        ->and($this->servers->mysqlPassword($server))->toBe('s3cret!')
        ->and($server->toArray())->not->toHaveKey('mysql_password');
});

test('invalid input is rejected with a message', function (array $override, string $message) {
    $this->servers->create($this->admin, array_merge($this->input, $override));
})->throws(DomainException::class)->with([
    [['name' => ''], 'name'],
    [['hostname' => 'not a host!'], 'Hostname'],
    [['ssh_port' => '70000'], 'SSH port'],
    [['ssh_username' => 'bad user'], 'SSH username'],
    [['mysql_username' => ''], 'MySQL username'],
    [['mysql_password' => ''], 'MySQL password'],
    [['mysql_host' => '-bad-'], 'MySQL host'],
]);

test('names are unique', function () {
    $this->servers->create($this->admin, $this->input);
    $this->servers->create($this->admin, $this->input);
})->throws(DomainException::class, 'already');

test('a blank password on edit keeps the stored one', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_password' => '']));

    expect($this->servers->mysqlPassword($server->fresh()))->toBe('s3cret!');
});

test('turning MySQL off forgets its credentials', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_enabled' => '']));
    $server = $server->fresh();

    expect($server->mysql_enabled)->toBeFalse()
        ->and($server->mysql_password)->toBeNull()
        ->and($server->mysql_username)->toBeNull();
});

test('changing the hostname or port forgets the trusted host key', function (array $change, bool $kept) {
    $server = $this->servers->create($this->admin, $this->input);
    $this->servers->trustHostKey($this->admin, $server, new HostKey('ssh-ed25519', base64_encode('key')));
    $this->servers->update($this->admin, $server, array_merge($this->input, $change));

    expect($server->fresh()->ssh_host_key !== null)->toBe($kept);
})->with([
    'rename only' => [['name' => 'web-one'], true],
    'new hostname' => [['hostname' => 'web2.example.com'], false],
    'new port' => [['ssh_port' => '22'], false],
]);

test('non-admins cannot change servers', function (string $method) {
    $server = $this->servers->create($this->admin, $this->input);

    match ($method) {
        'create' => $this->servers->create($this->user, $this->input),
        'update' => $this->servers->update($this->user, $server, $this->input),
        'delete' => $this->servers->delete($this->user, $server),
        'trust' => $this->servers->trustHostKey($this->user, $server, new HostKey('ssh-ed25519', base64_encode('key'))),
    };
})->throws(AuthorizationException::class)->with(['create', 'update', 'delete', 'trust']);

test('servers are listed by name', function () {
    foreach (['web-10', 'db-1', 'web-2'] as $name) {
        $this->servers->create($this->admin, array_merge($this->input, ['name' => $name]));
    }

    expect(array_map(fn (Server $s) => $s->name, $this->servers->all()))->toBe(['db-1', 'web-2', 'web-10']);
});
