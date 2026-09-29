<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\Account;
use App\Models\PasswordReveal;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\LoginThrottleService;
use App\Services\ServerService;
use App\Services\SettingsService;
use App\Services\TotpService;
use OTPHP\TOTP;

beforeEach(function () {
    $this->totp = new TotpService($this->clock);
    $this->accounts = new AccountService($this->cipher, new AuthService($this->passwords, $this->totp, $this->cipher, new LoginThrottleService($this->clock)), new SettingsService(), $this->clock);
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->admin->save();
    $this->adminSecret = $this->totp->generateSecret();
    $this->admin->must_change_password = false;
    $this->admin->totp_secret = $this->cipher->encrypt($this->adminSecret, 'totp-secret:' . $this->admin->id);
    $this->admin->save();
    $this->code = fn () => TOTP::createFromSecret($this->adminSecret)->at($this->clock->time);
    $this->server = fn (string $name, string $user = 'deploy') => $this->servers->create($this->admin, [
        'name' => $name, 'hostname' => "$name.example.com", 'ssh_port' => 22, 'ssh_username' => $user,
    ]);
});

test('every SSH server gets a local account for its SSH user', function () {
    $web = ($this->server)('web');
    $account = Account::query()->find($web->ssh_account_id);

    expect($account->type)->toBe(Account::TYPE_LOCAL)
        ->and($account->username)->toBe('deploy')
        ->and($account->server_id)->toBe($web->id)
        ->and($account->servers->pluck('name')->all())->toBe(['web']);
});

test('LDAP and shared accounts can log into several servers', function () {
    $ldap = $this->accounts->create($this->admin, ['username' => 'CORP\\svc_mon', 'type' => 'ldap', 'password' => 'ldap-pw!', 'rotation_days' => '90']);
    $web = ($this->server)('web');
    $db = ($this->server)('db');

    foreach ([$web, $db] as $server) {
        $this->servers->update($this->admin, $server, ['name' => $server->name, 'hostname' => $server->hostname, 'ssh_port' => 22, 'ssh_account_id' => $ldap->id]);
    }

    expect($web->fresh()->ssh_username)->toBe('CORP\\svc_mon')
        ->and($ldap->fresh()->servers->pluck('name')->sort()->values()->all())->toBe(['db', 'web'])
        ->and($this->servers->sshPassword($db->fresh()))->toBe('ldap-pw!');
});

test('a local account of one server cannot be used on another', function () {
    $web = ($this->server)('web');
    $db = ($this->server)('db');

    $this->servers->update($this->admin, $db, ['name' => 'db', 'hostname' => 'db.example.com', 'ssh_port' => 22, 'ssh_account_id' => $web->ssh_account_id]);
})->throws(DomainException::class);

test('account input is validated', function (array $input, string $message) {
    ($this->server)('web');
    $this->accounts->create($this->admin, $input);
})->throws(DomainException::class)->with([
    [['username' => '', 'type' => 'ldap'], 'username'],
    [['username' => 'with space', 'type' => 'ldap'], 'username'],
    [['username' => 'x', 'type' => 'nope'], 'type'],
    [['username' => 'x', 'type' => 'local'], 'server'],
    [['username' => 'x', 'type' => 'shared', 'rotation_days' => '0'], 'Rotation'],
    [['username' => 'x', 'type' => 'shared', 'password' => 'p', 'password_changed_at' => '2999-01-01'], 'date'],
]);

test('the same account cannot be tracked twice', function () {
    $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared']);
    $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared']);
})->throws(DomainException::class, 'already tracked');

test('passwords are stored encrypted with the reset date', function () {
    $account = $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared', 'password' => 'shared-pw!', 'password_changed_at' => '2026-01-15']);

    expect($account->password)->not->toContain('shared-pw!')
        ->and($this->accounts->password($account))->toBe('shared-pw!')
        ->and($account->password_changed_at->toDateString())->toBe('2026-01-15');
});

test('rotation status: none, unknown, ok, due soon, overdue', function () {
    $account = $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared']);

    expect($this->accounts->status($account))->toBe(AccountService::STATUS_NONE);

    $this->accounts->update($this->admin, $account, ['username' => 'backup', 'type' => 'shared', 'rotation_days' => '30']);
    expect($this->accounts->status($account))->toBe(AccountService::STATUS_UNKNOWN);

    $this->accounts->recordPassword($this->admin, $account, 'pw-1');
    expect($this->accounts->status($account))->toBe(AccountService::STATUS_OK)
        ->and($this->accounts->dueAt($account)->diffInDays($account->password_changed_at, true))->toEqual(30.0);

    $this->clock->advance(24 * 86400);
    expect($this->accounts->status($account))->toBe(AccountService::STATUS_DUE_SOON);

    $this->clock->advance(7 * 86400);
    expect($this->accounts->status($account))->toBe(AccountService::STATUS_OVERDUE);

    $this->accounts->recordPassword($this->admin, $account, 'pw-2');
    expect($this->accounts->status($account))->toBe(AccountService::STATUS_OK);
});

test('revealing needs an authenticator code, used once, is logged, and wrong attempts are limited', function () {
    $account = $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared', 'password' => 'shared-pw!']);
    $code = ($this->code)();

    expect(fn () => $this->accounts->reveal($this->admin, $account, ['code' => '000000'], '10.0.0.1'))->toThrow(InvalidCredentialsException::class)
        ->and(PasswordReveal::count())->toBe(0)
        ->and($this->accounts->reveal($this->admin, $account, ['code' => $code], '10.0.0.1'))->toBe('shared-pw!')
        ->and(fn () => $this->accounts->reveal($this->admin, $account, ['code' => $code], '10.0.0.1'))->toThrow(InvalidCredentialsException::class)
        ->and(PasswordReveal::count())->toBe(1)
        ->and($this->accounts->reveals($account)[0]->user->username)->toBe('admin');

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        try {
            $this->accounts->reveal($this->admin, $account, ['code' => '000000'], '10.0.0.1');
        } catch (InvalidCredentialsException | TooManyAttemptsException) {
        }
    }

    $this->clock->advance(30);

    expect(fn () => $this->accounts->reveal($this->admin, $account, ['code' => ($this->code)()], '10.0.0.1'))->toThrow(TooManyAttemptsException::class);
});

test('non-admins cannot manage or reveal accounts', function () {
    $account = $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared', 'password' => 'p']);
    $user = new User(['role' => User::ROLE_USER]);

    expect(fn () => $this->accounts->reveal($user, $account, ['code' => 'x'], '10.0.0.1'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->accounts->create($user, ['username' => 'x', 'type' => 'shared']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->accounts->delete($user, $account))->toThrow(AuthorizationException::class);
});

test('an account a server logs in with cannot be deleted', function () {
    $web = ($this->server)('web');
    $account = Account::query()->find($web->ssh_account_id);

    expect(fn () => $this->accounts->delete($this->admin, $account))->toThrow(DomainException::class, 'web');

    $spare = $this->accounts->create($this->admin, ['username' => 'spare', 'type' => 'shared']);
    $this->accounts->delete($this->admin, $spare);

    expect(Account::query()->find($spare->id))->toBeNull();
});

test('logins are tracked per account and server', function () {
    $web = ($this->server)('web');
    $this->accounts->recordUse($web);
    $pivot = Account::query()->find($web->ssh_account_id)->servers->first()->pivot;

    expect(strtotime($pivot->last_used_at))->toBe($this->clock->time);
});

test('SSH passwords stored on servers before accounts existed move into the accounts', function () {
    $web = ($this->server)('web');
    $web->ssh_account_id = null;
    $web->ssh_password = $this->cipher->encrypt('old-pw!', 'ssh-password:' . $web->id);
    $web->save();
    Account::query()->delete();

    $this->accounts->syncServers($this->servers);
    $this->accounts->syncServers($this->servers);
    $web = $web->fresh();

    expect(Account::count())->toBe(1)
        ->and($web->ssh_password)->toBeNull()
        ->and($this->servers->sshPassword($web))->toBe('old-pw!');
});

test('MySQL passwords stored on servers before accounts existed move into database accounts', function () {
    $web = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'new-pw!',
    ]);
    // As a server looked before accounts: password on the server, no account.
    $web->mysql_account_id = null;
    $web->mysql_password = $this->cipher->encrypt('old-db-pw!', 'mysql-password:' . $web->id);
    $web->save();
    Account::query()->delete();

    expect($this->servers->mysqlPassword($web->fresh()))->toBe('old-db-pw!');

    $this->accounts->syncServers($this->servers);
    $this->accounts->syncServers($this->servers);
    $web = $web->fresh();
    $account = Account::query()->find($web->mysql_account_id);

    expect(Account::count())->toBe(1)
        ->and($account->service)->toBe(Account::SERVICE_MYSQL)
        ->and($web->mysql_password)->toBeNull()
        ->and($this->servers->mysqlPassword($web))->toBe('old-db-pw!');
});

test('an account a server logs into its database with cannot be deleted', function () {
    $db = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!',
    ]);

    expect(fn () => $this->accounts->delete($this->admin, Account::query()->find($db->mysql_account_id)))->toThrow(DomainException::class, 'database');
});

test('local accounts are a system or a database user; the same name can be both on one server', function () {
    $web = ($this->server)('web');
    $db = $this->accounts->create($this->admin, ['username' => 'deploy', 'type' => 'local', 'server_id' => (string) $web->id, 'service' => 'mysql']);

    expect($db->typeLabel())->toBe('Local database user')
        ->and(Account::query()->find($web->ssh_account_id)->typeLabel())->toBe('Local system user')
        ->and(fn () => $this->accounts->create($this->admin, ['username' => 'deploy', 'type' => 'local', 'server_id' => (string) $web->id, 'service' => 'mysql']))->toThrow(DomainException::class, 'already')
        ->and(collect($this->accounts->selectableFor($web, Account::SERVICE_MYSQL))->pluck('id')->all())->toBe([$db->id]);
});

test('accounts are listed per service', function () {
    $web = ($this->server)('web');
    $this->accounts->create($this->admin, ['username' => 'dbmon', 'type' => 'shared', 'service' => 'mysql']);

    expect(collect($this->accounts->all(Account::SERVICE_SSH))->pluck('username')->all())->toBe(['deploy'])
        ->and(collect($this->accounts->all(Account::SERVICE_MYSQL))->pluck('username')->all())->toBe(['dbmon']);
});

test('an account used for both SSH and database logins before they were kept apart is split in two', function () {
    $ldap = $this->accounts->create($this->admin, ['username' => 'CORP\\ops', 'type' => 'ldap', 'password' => 'both-pw!', 'password_changed_at' => '2026-02-01', 'rotation_days' => '90']);
    $ldap->service = null;
    $ldap->save();
    $web = $this->servers->create($this->admin, [
        'name' => 'web', 'hostname' => 'web.example.com', 'ssh_port' => 22, 'ssh_account_id' => (string) $ldap->id,
        'mysql_enabled' => '1', 'mysql_account_id' => (string) $ldap->id, 'mysql_password' => '', 'mysql_tls' => 'off',
    ]);
    $dbOnly = $this->accounts->create($this->admin, ['username' => 'dbmon', 'type' => 'shared', 'password' => 'db-pw!']);
    $dbOnly->service = null;
    $dbOnly->save();
    $db = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_account_id' => (string) $dbOnly->id, 'mysql_password' => '', 'mysql_tls' => 'off',
    ]);

    $this->accounts->syncServers($this->servers);
    $this->accounts->syncServers($this->servers);
    $web = $web->fresh();
    $copy = Account::query()->find($web->mysql_account_id);

    expect($ldap->fresh()->service)->toBe(Account::SERVICE_SSH)
        ->and($web->ssh_account_id)->toBe($ldap->id)
        ->and($copy->id)->not->toBe($ldap->id)
        ->and($copy->service)->toBe(Account::SERVICE_MYSQL)
        ->and($copy->username)->toBe('CORP\\ops')
        ->and($copy->rotation_days)->toBe(90)
        ->and($copy->password_changed_at->format('Y-m-d'))->toBe($ldap->fresh()->password_changed_at->format('Y-m-d'))
        ->and($this->servers->mysqlPassword($web))->toBe('both-pw!')
        ->and($ldap->fresh()->servers->pluck('id')->all())->toBe([$web->id])
        ->and($dbOnly->fresh()->service)->toBe(Account::SERVICE_MYSQL)
        ->and($db->fresh()->mysql_account_id)->toBe($dbOnly->id)
        ->and(Account::query()->where('username', 'CORP\\ops')->count())->toBe(2);
});

test('an account in use can\'t switch between SSH and database', function () {
    $web = ($this->server)('web');
    $deploy = Account::query()->find($web->ssh_account_id);

    expect(fn () => $this->accounts->update($this->admin, $deploy, ['username' => 'deploy', 'type' => 'local', 'server_id' => (string) $web->id, 'service' => 'mysql']))
        ->toThrow(DomainException::class, 'kept apart');
});

test('the server form offers only accounts of its own service', function () {
    $web = ($this->server)('web');
    $sshLdap = $this->accounts->create($this->admin, ['username' => 'CORP\\ops', 'type' => 'ldap', 'service' => 'ssh']);
    $dbShared = $this->accounts->create($this->admin, ['username' => 'dbmon', 'type' => 'shared', 'service' => 'mysql']);

    expect(collect($this->accounts->selectableFor($web, Account::SERVICE_SSH))->pluck('id')->all())->toContain($sshLdap->id)->not->toContain($dbShared->id)
        ->and(collect($this->accounts->selectableFor(null, Account::SERVICE_MYSQL))->pluck('id')->all())->toBe([$dbShared->id]);
});

test('importing MariaDB users takes only name@%, without passwords, and records where each came from', function () {
    $db = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!',
    ]);
    $importer = new class ($this->cipher, $this->clock) extends AccountService {
        public function __construct($cipher, $clock)
        {
            parent::__construct($cipher, null, new SettingsService(), $clock);
        }

        protected function mysqlUsers(Server $server): array
        {
            return [
                ['name' => 'app', 'host' => '%', 'plugin' => 'mysql_native_password', 'role' => false],
                ['name' => 'app', 'host' => 'localhost', 'plugin' => 'mysql_native_password', 'role' => false],
                ['name' => 'root', 'host' => 'localhost', 'plugin' => 'unix_socket', 'role' => false],
                ['name' => 'report', 'host' => '%', 'plugin' => '', 'role' => false],
                ['name' => 'PUBLIC', 'host' => '', 'plugin' => '', 'role' => true],
                ['name' => '', 'host' => '%', 'plugin' => '', 'role' => false],
                ['name' => 'mon', 'host' => '%', 'plugin' => '', 'role' => false], // already tracked from the server's settings
            ];
        }
    };

    $result = $importer->importMysqlUsers($this->admin, $db);
    $app = Account::query()->where('username', 'app')->first();

    expect($result)->toBe(['added' => ['app', 'report'], 'existing' => ['mon'], 'ignored' => 4])
        ->and($app->service)->toBe(Account::SERVICE_MYSQL)
        ->and($app->server_id)->toBe($db->id)
        ->and($app->password)->toBeNull()
        ->and($app->canLogIn())->toBeFalse()
        ->and($app->origin)->toBe(Account::ORIGIN_IMPORT)
        ->and($app->origin_detail)->toBe("'app'@'%', mysql_native_password")
        ->and($app->originLabel())->toContain("Imported from db's MariaDB users ('app'@'%', mysql_native_password) by admin")
        ->and($app->servers)->toHaveCount(0)
        ->and(Account::query()->find($db->mysql_account_id)->origin)->toBe(Account::ORIGIN_SERVER)
        // Importing again adds nothing.
        ->and($importer->importMysqlUsers($this->admin, $db)['added'])->toBe([]);
});

test('an imported account can\'t log in until its password is recorded', function () {
    $db = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!',
    ]);
    $imported = Account::query()->create(['username' => 'app', 'type' => 'local', 'server_id' => $db->id, 'service' => 'mysql', 'origin' => Account::ORIGIN_IMPORT, 'origin_server_id' => $db->id]);
    $settings = ['name' => 'db', 'hostname' => 'db.example.com', 'ssh_enabled' => '', 'mysql_enabled' => '1', 'mysql_tls' => 'off'];

    expect($imported->usableFor($db, Account::SERVICE_MYSQL))->toBeFalse()
        ->and(fn () => $this->servers->update($this->admin, $db, $settings + ['mysql_account_id' => (string) $imported->id, 'mysql_password' => '']))->toThrow(DomainException::class, 'imported without a password')
        // Naming it on the server form without a password is refused too ...
        ->and(fn () => $this->servers->update($this->admin, $db->fresh(), $settings + ['mysql_username' => 'app', 'mysql_password' => '']))->toThrow(DomainException::class, 'none is stored');

    // ... and with one, the password is recorded and the account can log in.
    $this->servers->update($this->admin, $db->fresh(), $settings + ['mysql_username' => 'app', 'mysql_password' => 'app-pw!']);

    expect($db->fresh()->mysql_account_id)->toBe($imported->id)
        ->and($imported->fresh()->canLogIn())->toBeTrue()
        ->and($this->servers->mysqlPassword($db->fresh()))->toBe('app-pw!');
});

test('accounts added by hand record who added them; only admins import', function () {
    $shared = $this->accounts->create($this->admin, ['username' => 'dbmon', 'type' => 'shared', 'service' => 'mysql']);
    $db = $this->servers->create($this->admin, ['name' => 'web2', 'hostname' => 'web2.example.com', 'ssh_enabled' => '']);

    expect($shared->origin)->toBe(Account::ORIGIN_MANUAL)
        ->and($shared->origin_user_id)->toBe($this->admin->id)
        ->and($shared->originLabel())->toStartWith('Added by admin')
        ->and(fn () => $this->accounts->importMysqlUsers(new User(['role' => User::ROLE_USER]), $db))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->accounts->importMysqlUsers($this->admin, $db))->toThrow(DomainException::class, 'no MariaDB');
});
