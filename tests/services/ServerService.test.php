<?php

use App\DTOs\HostKey;
use App\Exceptions\AuthorizationException;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
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

test('admins create servers; the MySQL login becomes a local database user with its password encrypted', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $account = Account::query()->find($server->mysql_account_id);

    expect($server->hostname)->toBe('web1.example.com')
        ->and($server->ssh_port)->toBe(2222)
        ->and($server->mysqlHost())->toBe('web1.example.com')
        ->and($server->mysql_password)->toBeNull()
        ->and($account->type)->toBe(Account::TYPE_LOCAL)
        ->and($account->service)->toBe(Account::SERVICE_MYSQL)
        ->and($account->username)->toBe('sys_monitor')
        ->and($account->server_id)->toBe($server->id)
        ->and($account->password)->not->toContain('s3cret')
        ->and($this->servers->mysqlPassword($server))->toBe('s3cret!')
        ->and($account->toArray())->not->toHaveKey('password');
});

test('the SSH user and a database user with the same name are separate accounts', function () {
    $server = $this->servers->create($this->admin, array_merge($this->input, ['ssh_username' => 'monitor', 'mysql_username' => 'monitor']));

    expect($server->ssh_account_id)->not->toBe($server->mysql_account_id)
        ->and(Account::query()->find($server->ssh_account_id)->localService())->toBe(Account::SERVICE_SSH)
        ->and($this->servers->mysqlPassword($server))->toBe('s3cret!')
        ->and($this->servers->sshPassword($server))->toBeNull();
});

test('a shared or LDAP account can be the database login, using its stored password', function () {
    $accounts = new AccountService($this->cipher);
    $ldap = $accounts->create($this->admin, ['username' => 'CORP\\dbmon', 'type' => 'ldap', 'password' => 'ldap-db-pw!']);
    $server = $this->servers->create($this->admin, array_merge($this->input, ['mysql_account_id' => (string) $ldap->id, 'mysql_username' => '', 'mysql_password' => '']));

    expect($server->mysql_account_id)->toBe($ldap->id)
        ->and($server->mysql_username)->toBe('CORP\\dbmon')
        ->and($this->servers->mysqlPassword($server))->toBe('ldap-db-pw!')
        ->and($ldap->servers->pluck('id')->all())->toBe([$server->id]);
});

test('a database account without a stored password needs one typed', function () {
    $shared = (new AccountService($this->cipher))->create($this->admin, ['username' => 'dbmon', 'type' => 'shared']);
    $this->servers->create($this->admin, array_merge($this->input, ['mysql_account_id' => (string) $shared->id, 'mysql_password' => '']));
})->throws(DomainException::class, 'none is stored');

test('another server\'s local user, or an SSH user, is not a usable database account', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $other = $this->servers->create($this->admin, array_merge($this->input, ['name' => 'web-2']));

    expect(fn () => $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_account_id' => (string) $server->ssh_account_id])))->toThrow(DomainException::class, 'database account')
        ->and(fn () => $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_account_id' => (string) $other->mysql_account_id])))->toThrow(DomainException::class, 'database account');
});

test('database logins count as account use', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $this->servers->recordMysqlUse($server);

    expect(Account::query()->find($server->mysql_account_id)->servers->first()->pivot->last_used_at)->not->toBeNull();
});

test('renaming an account renames the login on servers using it', function () {
    $accounts = new AccountService($this->cipher);
    $shared = $accounts->create($this->admin, ['username' => 'dbmon', 'type' => 'shared', 'password' => 'pw!']);
    $server = $this->servers->create($this->admin, array_merge($this->input, ['mysql_account_id' => (string) $shared->id, 'mysql_password' => '']));
    $accounts->update($this->admin, $shared, ['username' => 'dbmon2', 'type' => 'shared']);

    expect($server->fresh()->mysql_username)->toBe('dbmon2');
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

test('TLS defaults to verify and a pasted CA is validated', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'Internal CA'], $key), null, $key, 30), $ca);

    $server = $this->servers->create($this->admin, $this->input);

    expect($server->mysql_tls)->toBe(Server::TLS_VERIFY)
        ->and($server->mysql_tls_ca)->toBeNull();

    $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_tls' => 'verify', 'mysql_tls_ca' => $ca]));

    expect($server->fresh()->mysql_tls_ca)->toContain('BEGIN CERTIFICATE');

    // The CA only applies when verifying.
    $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_tls' => 'encrypt', 'mysql_tls_ca' => $ca]));

    expect($server->fresh()->mysql_tls_ca)->toBeNull();
});

test('bad TLS settings are rejected', function (array $override) {
    $this->servers->create($this->admin, array_merge($this->input, $override));
})->throws(DomainException::class)->with([
    [['mysql_tls' => 'maybe']],
    [['mysql_tls' => 'verify', 'mysql_tls_ca' => 'not a certificate']],
]);

test('turning MySQL off also resets TLS', function () {
    $server = $this->servers->create($this->admin, $this->input);
    $this->servers->update($this->admin, $server, array_merge($this->input, ['mysql_enabled' => '']));

    expect($server->fresh()->mysql_tls)->toBe(Server::TLS_OFF);
});

test('turning SSH off forgets the host key and stored password', function () {
    $server = $this->servers->create($this->admin, array_merge($this->input, ['ssh_password_allowed' => '1']));
    $this->servers->trustHostKey($this->admin, $server, new HostKey('ssh-ed25519', base64_encode('key')));
    $this->servers->usePasswordAuth($server, 'pw');
    $this->servers->update($this->admin, $server, array_merge($this->input, ['ssh_enabled' => '']));
    $server = $server->fresh();

    expect($server->ssh_host_key)->toBeNull()
        ->and($server->ssh_password)->toBeNull()
        ->and($server->sshReady())->toBeFalse();
});

test('SSH is optional: a server can exist just to serve SSL certificates', function () {
    $server = $this->servers->create($this->admin, ['name' => 'site', 'hostname' => 'site.example.com', 'ssh_enabled' => '']);

    expect($server->ssh_enabled)->toBeFalse()
        ->and($server->ssh_username)->toBe('')
        ->and($server->ssh_account_id)->toBeNull();
});
