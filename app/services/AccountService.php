<?php

namespace App\Services;

use App\Enums\LoginStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use App\Models\Account;
use App\Models\PasswordReveal;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use Psr\Clock\ClockInterface;

/**
 * Tracked accounts: local accounts on a server, LDAP and shared accounts.
 * Each has a current password (encrypted, revealed to admins only after
 * they confirm with an authenticator code, every reveal logged), when it was last
 * reset and a rotation period. Tracks which accounts log into which servers
 * and when the app last used each one.
 *
 * Recording a password here doesn't change it anywhere else: the app tracks
 * passwords, it doesn't set them.
 */
class AccountService
{
    public const STATUS_NONE = 'none';

    public const STATUS_OK = 'ok';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        private readonly SecretCipher $cipher = new SecretCipher(),
        private ?AuthService $auth = null,
        private readonly SettingsService $settings = new SettingsService(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * @return list<Account>
     */
    public function all(): array
    {
        return Account::query()->with(['servers', 'homeServer'])->get()
            ->sortBy(fn (Account $a) => [$this->statusRank($a), strtolower($a->username)])
            ->values()
            ->all();
    }

    /**
     * LDAP and shared accounts, plus the given server's own local accounts:
     * the accounts that server can log in with.
     *
     * @return list<Account>
     */
    public function selectableFor(?Server $server): array
    {
        return Account::query()->get()
            ->filter(fn (Account $a) => $a->type !== Account::TYPE_LOCAL || ($server?->id !== null && $a->server_id === $server->id))
            ->sortBy(fn (Account $a) => [$a->type, strtolower($a->username)])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws DomainException with a user-facing message
     */
    public function create(User $admin, array $input): Account
    {
        $this->requireAdmin($admin);
        $account = new Account();
        $this->fill($account, $input);
        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            $this->storePassword($account, $password, $this->date($input['password_changed_at'] ?? null));
        }

        return $account;
    }

    /**
     * Change details (not the password; see recordPassword()).
     *
     * @param array<string, mixed> $input
     *
     * @throws DomainException
     */
    public function update(User $admin, Account $account, array $input): void
    {
        $this->requireAdmin($admin);
        $this->fill($account, $input);
    }

    /**
     * Record a new current password, reset on $changedAt (default now).
     */
    public function recordPassword(User $admin, Account $account, string $password, mixed $changedAt = null): void
    {
        $this->requireAdmin($admin);

        if ($password === '') {
            throw new DomainException('Enter the new password.');
        }

        $this->storePassword($account, $password, $this->date($changedAt));
    }

    /**
     * Save a password without an admin check, for the app's own flows (SSH
     * setup storing the password it was given).
     */
    public function storePassword(Account $account, string $password, ?Carbon $changedAt = null): void
    {
        if (!$account->exists) {
            $account->save();
        }

        $account->password = $this->cipher->encrypt($password, $this->context($account));
        $account->password_changed_at = $changedAt ?? Carbon::instance($this->clock->now());
        $account->save();
    }

    /**
     * The decrypted password, for the app's own logins. Never for views: use reveal().
     */
    public function password(Account $account): ?string
    {
        return $account->password === null ? null : $this->cipher->decrypt($account->password, $this->context($account));
    }

    /**
     * Show an admin the password after they confirm with an authenticator code; logged.
     *
     * @throws InvalidCredentialsException if the admin's password is wrong
     * @throws TooManyAttemptsException
     * @throws DomainException if no password is stored
     */
    public function reveal(User $admin, Account $account, string $code, string $ip): string
    {
        $this->requireAdmin($admin);
        $this->auth ??= new AuthService(cipher: $this->cipher);
        $result = $this->auth->confirm($admin, $code, $ip);

        if ($result->status === LoginStatus::TooManyAttempts) {
            throw new TooManyAttemptsException($result->retryAfter);
        }

        if ($result->status !== LoginStatus::Success) {
            throw new InvalidCredentialsException('Your authenticator code is wrong.');
        }

        $password = $this->password($account);

        if ($password === null) {
            throw new DomainException('No password is stored for this account.');
        }

        PasswordReveal::query()->create([
            'account_id' => $account->id,
            'user_id' => $admin->id,
            'revealed_at' => Carbon::instance($this->clock->now()),
        ]);

        return $password;
    }

    /**
     * @throws DomainException if a server still logs in with it
     */
    public function delete(User $admin, Account $account): void
    {
        $this->requireAdmin($admin);
        $users = Server::query()->where('ssh_account_id', $account->id)->pluck('name');

        if ($users->isNotEmpty()) {
            throw new DomainException('Servers still log in with this account: ' . $users->implode(', ') . '. Change their SSH account first.');
        }

        $account->getConnection()->transaction(function () use ($account) {
            $account->servers()->detach();
            PasswordReveal::query()->where('account_id', $account->id)->delete();
            $account->delete();
        });
    }

    public function dueAt(Account $account): ?Carbon
    {
        if ($account->rotation_days === null || $account->password_changed_at === null) {
            return null;
        }

        return $account->password_changed_at->copy()->addDays($account->rotation_days);
    }

    /**
     * none (no rotation), ok, due_soon, overdue, or unknown (rotation set but
     * no reset date recorded).
     */
    public function status(Account $account): string
    {
        if ($account->rotation_days === null) {
            return self::STATUS_NONE;
        }

        $due = $this->dueAt($account);

        if ($due === null) {
            return self::STATUS_UNKNOWN;
        }

        $now = Carbon::instance($this->clock->now());

        return match (true) {
            $due->lessThanOrEqualTo($now) => self::STATUS_OVERDUE,
            $due->lessThanOrEqualTo($now->copy()->addDays($this->settings->accountWarningDays())) => self::STATUS_DUE_SOON,
            default => self::STATUS_OK,
        };
    }

    /**
     * The account a server logs in with over SSH; for older servers, a local
     * account is created from the SSH username (see syncServers()).
     */
    public function forServer(Server $server): ?Account
    {
        if (!$server->ssh_enabled) {
            return null;
        }

        $account = $server->ssh_account_id !== null ? Account::query()->find($server->ssh_account_id) : null;

        if ($account instanceof Account) {
            return $account;
        }

        return $this->assignLocal($server, $server->ssh_username);
    }

    /**
     * Point a server's SSH at an account: an existing LDAP/shared (or its own
     * local) account by id, or else a local account for $username, created if
     * needed. Keeps ssh_username in step with the account.
     *
     * @throws DomainException if the chosen account can't be used on this server
     */
    public function assign(Server $server, ?int $accountId, string $username): Account
    {
        if ($accountId !== null) {
            $account = Account::query()->find($accountId);

            if (!$account instanceof Account || ($account->type === Account::TYPE_LOCAL && $account->server_id !== $server->id)) {
                throw new DomainException('That account can\'t be used on this server.');
            }

            return $this->link($server, $account);
        }

        return $this->assignLocal($server, $username);
    }

    /**
     * Note that the app just logged into a server with its SSH account.
     */
    public function recordUse(Server $server): void
    {
        if ($server->ssh_account_id === null) {
            return;
        }

        $account = Account::query()->find($server->ssh_account_id);

        if ($account instanceof Account) {
            $account->servers()->syncWithoutDetaching([$server->id => ['last_used_at' => Carbon::instance($this->clock->now())]]);
        }
    }

    /**
     * Give every SSH server an account, and move SSH passwords stored on
     * servers (before accounts existed) into those accounts. Idempotent.
     */
    public function syncServers(ServerService $servers): void
    {
        foreach (Server::query()->where('ssh_enabled', true)->get() as $server) {
            $account = $this->forServer($server);

            if ($account !== null && $server->ssh_password !== null) {
                $legacy = $servers->legacySshPassword($server);

                if ($legacy !== null && $account->password === null) {
                    $this->storePassword($account, $legacy);
                }

                $server->ssh_password = null;
                $server->save();
            }
        }
    }

    /**
     * @return list<PasswordReveal>
     */
    public function reveals(Account $account, int $limit = 20): array
    {
        return PasswordReveal::query()->with('user')->where('account_id', $account->id)->get()
            ->sortByDesc('revealed_at')->take($limit)->values()->all();
    }

    private function assignLocal(Server $server, string $username): Account
    {
        $account = Account::query()
            ->where('type', Account::TYPE_LOCAL)
            ->where('server_id', $server->id)
            ->where('username', $username)
            ->first() ?? Account::query()->create(['username' => $username, 'type' => Account::TYPE_LOCAL, 'server_id' => $server->id]);

        return $this->link($server, $account);
    }

    private function link(Server $server, Account $account): Account
    {
        $account->servers()->syncWithoutDetaching([$server->id]);

        if ($server->ssh_account_id !== $account->id || $server->ssh_username !== $account->username) {
            $server->ssh_account_id = $account->id;
            $server->ssh_username = $account->username;
            $server->save();
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fill(Account $account, array $input): void
    {
        $username = trim((string) ($input['username'] ?? ''));
        $type = (string) ($input['type'] ?? $account->type ?? '');
        $serverId = filter_var($input['server_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $rotation = trim((string) ($input['rotation_days'] ?? ''));

        if ($username === '' || mb_strlen($username) > 256 || preg_match('/\s/', $username) === 1) {
            throw new DomainException('Enter the username (no spaces; e.g. deploy, CORP\\jsmith or jsmith@corp.example).');
        }

        if (!in_array($type, Account::TYPES, true)) {
            throw new DomainException('Choose the account type.');
        }

        if ($type === Account::TYPE_LOCAL) {
            if ($serverId === null || !Server::query()->whereKey($serverId)->exists()) {
                throw new DomainException('Choose the server this local account belongs to.');
            }
        } else {
            $serverId = null;
        }

        $duplicate = Account::query()->where('type', $type)->where('username', $username)->where('id', '!=', $account->id ?? 0);

        if ($type === Account::TYPE_LOCAL) {
            $duplicate->where('server_id', $serverId);
        }

        if ($duplicate->exists()) {
            throw new DomainException('That account is already tracked.');
        }

        if ($rotation !== '' && filter_var($rotation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]) === false) {
            throw new DomainException('Rotation must be a whole number of days from 1 to 3650, or empty for none.');
        }

        $account->username = $username;
        $account->type = $type;
        $account->server_id = $serverId;
        $account->rotation_days = $rotation === '' ? null : (int) $rotation;
        $account->notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000) ?: null;

        if ($account->exists && $account->isDirty('server_id')) {
            // A local account moved to another server no longer logs into its old one.
            $account->servers()->detach();
        }

        $account->save();

        if ($type === Account::TYPE_LOCAL) {
            $account->servers()->syncWithoutDetaching([$serverId]);
        }
    }

    private function date(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, \App\Utils\LocalTime::zone());
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value || $date->isFuture()) {
            throw new DomainException('Enter the reset date as YYYY-MM-DD, not in the future.');
        }

        return $date->startOfDay()->utc();
    }

    private function statusRank(Account $account): int
    {
        return match ($this->status($account)) {
            self::STATUS_OVERDUE => 0,
            self::STATUS_DUE_SOON => 1,
            self::STATUS_UNKNOWN => 2,
            default => 3,
        };
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage accounts.');
        }
    }

    private function context(Account $account): string
    {
        return 'account-password:' . $account->id;
    }
}
