<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use App\Models\MariadbQuery;
use App\Models\QueryAccount;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use PDOException;
use Psr\Clock\ClockInterface;

/**
 * Each user's private list of database logins for the MariaDB query tool. Only the owner sees or uses
 * an account (every method checks), the password is encrypted (bound to the owner and the account) and
 * never shown again, and each account says which servers it's good on. An account is validated on entry:
 * it's only saved (added or changed) when its login works on every one of those servers.
 */
class QueryAccountService
{
    public const MAX_LABEL = 100;

    public const MAX_USERNAME = 128;

    /**
     * MySQL/MariaDB errors that mean the login itself was refused.
     */
    public const AUTH_ERRORS = [1045, 1698];

    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly SecretCipher $cipher = new SecretCipher(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * @return list<QueryAccount>
     */
    public function forUser(User $user): array
    {
        /** @var list<QueryAccount> $accounts */
        $accounts = QueryAccount::query()->where('user_id', $user->id)->orderBy('label')->orderBy('id')->get()->all();

        return $accounts;
    }

    /**
     * One of the user's own accounts; anyone else's is "not found".
     *
     * @throws DomainException
     */
    public function find(User $user, int $id): QueryAccount
    {
        $account = QueryAccount::query()->where('user_id', $user->id)->find($id);

        if (!$account instanceof QueryAccount) {
            throw new DomainException('No such account in your list.');
        }

        return $account;
    }

    /**
     * Add an account, or change one ($account; a blank password keeps the stored one), once its login
     * works on every server chosen: it's tried on them first (see login()), and nothing is saved when
     * any refuses it. The results come back either way.
     *
     * @param array{label?: mixed, username?: mixed, password?: mixed, servers?: mixed} $input
     * @return array{account: ?QueryAccount, results: list<array{server: Server, ok: bool, message: string}>} account: null when not saved
     *
     * @throws DomainException with a user-facing message (nothing tried or saved)
     */
    public function save(User $user, ?QueryAccount $account, array $input): array
    {
        if ($account !== null && $account->user_id !== $user->id) {
            throw new DomainException('No such account in your list.');
        }

        $label = trim((string) ($input['label'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $ids = array_values(array_unique(array_map('intval', (array) ($input['servers'] ?? []))));
        $available = array_map(fn (Server $s) => $s->id, $this->servers());
        $ids = array_values(array_intersect($ids, $available));

        if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
            throw new DomainException('Give the account a name of up to ' . self::MAX_LABEL . ' characters.');
        }

        if ($username === '' || mb_strlen($username) > self::MAX_USERNAME || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            throw new DomainException('Type the database username.');
        }

        if ($account === null && $password === '') {
            throw new DomainException('Type the password.');
        }

        if ($ids === []) {
            throw new DomainException('Choose the servers this account is for.');
        }

        // Validated on entry: the login has to work on every server chosen.
        $servers = array_values(array_filter($this->servers(), fn (Server $s) => in_array($s->id, $ids, true)));
        $results = $this->login($user, $username, $password !== '' ? $password : ($account === null ? '' : $this->password($account)), $servers, $label);

        if (count(array_filter($results, fn ($r) => $r['ok'])) !== count($results)) {
            return ['account' => null, 'results' => $results];
        }

        $account ??= new QueryAccount(['user_id' => $user->id]);
        $account->label = $label;
        $account->username = $username;
        $account->servers = $ids;
        $account->refused = null;
        $account->tested_at = Carbon::instance($this->clock->now());
        $account->save();

        // Encrypted once it has an id: bound to the owner and this account.
        if ($password !== '') {
            $account->password = $this->cipher->encrypt($password, $this->context($account));
            $account->save();
        }

        return ['account' => $account, 'results' => $results];
    }

    /**
     * @throws DomainException
     */
    public function delete(User $user, QueryAccount $account): void
    {
        if ($account->user_id !== $user->id) {
            throw new DomainException('No such account in your list.');
        }

        $account->delete();
    }

    /**
     * The account's password (for logging in; never shown).
     */
    public function password(QueryAccount $account): string
    {
        return $account->password === null ? '' : $this->cipher->decrypt($account->password, $this->context($account));
    }

    /**
     * Try a saved account's login again on its servers (e.g. after a password change on the servers) and
     * record which refused it; the query form then leaves those servers out of its choices.
     *
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException
     */
    public function test(User $user, QueryAccount $account): array
    {
        if ($account->user_id !== $user->id) {
            throw new DomainException('No such account in your list.');
        }

        $servers = array_values(array_filter($this->servers(), fn (Server $s) => $account->isFor($s)));
        $results = $this->login($user, $account->username, $this->password($account), $servers, $account->label);
        $refused = [];

        foreach ($results as $result) {
            if (!$result['ok']) {
                $refused[$result['server']->id] = mb_substr($result['message'], 0, 500);
            }
        }

        $account->refused = $refused === [] ? null : $refused;
        $account->tested_at = Carbon::instance($this->clock->now());
        $account->save();

        return $results;
    }

    /**
     * Log in once on each server. If the first refuses the login itself, the rest aren't tried: a
     * mistyped password shouldn't reach every server (and their fail2ban). Logged, and counted toward
     * the query tool's refused-login limit.
     *
     * @param list<Server> $servers
     * @return list<array{server: Server, ok: bool, message: string}>
     *
     * @throws DomainException when the user has had too many refused logins lately
     */
    private function login(User $user, string $username, string $password, array $servers, string $label): array
    {
        (new MariadbQueryService($this->mysql, $this->clock))->requireNotThrottled($user);
        $results = [];
        $stop = false;

        foreach ($servers as $i => $server) {
            if ($stop) {
                $results[] = ['server' => $server, 'ok' => false, 'message' => 'Not tried: the first server refused the login.'];

                continue;
            }

            try {
                $this->mysql->connectAs($server, $username, $password);
                $results[] = ['server' => $server, 'ok' => true, 'message' => 'The login works.'];
            } catch (ServerConnectionException $e) {
                $previous = $e->getPrevious();
                $auth = $previous instanceof PDOException && in_array((int) ($previous->errorInfo[1] ?? 0), self::AUTH_ERRORS, true);
                $results[] = ['server' => $server, 'ok' => false, 'message' => $e->getMessage()];
                $stop = $auth && $i === 0;
            }
        }

        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        MariadbQuery::query()->create([
            'user_id' => $user->id, 'db_user' => "$label ($username)", 'servers' => array_map(fn (Server $s) => $s->id, $servers),
            'statement' => '(login check)', 'writes' => false, 'outcome' => "$ok ok, " . (count($results) - $ok) . ' failed',
            'auth_failed' => $stop, 'created_at' => Carbon::instance($this->clock->now()),
        ]);

        return $results;
    }

    /**
     * @return list<Server>
     */
    private function servers(): array
    {
        return (new MariadbQueryService($this->mysql, $this->clock))->servers();
    }

    private function context(QueryAccount $account): string
    {
        return "query-account:{$account->user_id}:{$account->id}";
    }
}
