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

    expect(fn () => $this->accounts->reveal($this->admin, $account, '000000', '10.0.0.1'))->toThrow(InvalidCredentialsException::class)
        ->and(PasswordReveal::count())->toBe(0)
        ->and($this->accounts->reveal($this->admin, $account, $code, '10.0.0.1'))->toBe('shared-pw!')
        ->and(fn () => $this->accounts->reveal($this->admin, $account, $code, '10.0.0.1'))->toThrow(InvalidCredentialsException::class)
        ->and(PasswordReveal::count())->toBe(1)
        ->and($this->accounts->reveals($account)[0]->user->username)->toBe('admin');

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        try {
            $this->accounts->reveal($this->admin, $account, '000000', '10.0.0.1');
        } catch (InvalidCredentialsException | TooManyAttemptsException) {
        }
    }

    $this->clock->advance(30);

    expect(fn () => $this->accounts->reveal($this->admin, $account, ($this->code)(), '10.0.0.1'))->toThrow(TooManyAttemptsException::class);
});

test('non-admins cannot manage or reveal accounts', function () {
    $account = $this->accounts->create($this->admin, ['username' => 'backup', 'type' => 'shared', 'password' => 'p']);
    $user = new User(['role' => User::ROLE_USER]);

    expect(fn () => $this->accounts->reveal($user, $account, 'x', '10.0.0.1'))->toThrow(AuthorizationException::class)
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
