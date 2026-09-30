<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbQuery;
use App\Models\QueryAccount;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;

/**
 * The MariaDB multi-server query tool: one statement, run on every server chosen, each with a login
 * from the user's own private list (QueryAccountService), and the results collated into one table with a
 * Server column first. Admins may use a server's stored account instead (the monitoring login, often far
 * more privileged than a developer should have); other users never can. Any statement is allowed (the database's own privileges decide); one that isn't a
 * plain read must be confirmed. Each run is logged (MariadbQuery), without the password.
 *
 * Guarding against password guessing and fail2ban: when the first server refuses the login, the others
 * aren't tried; and a user with AUTH_FAILURES refused logins within AUTH_WINDOW_MINUTES has to wait.
 */
class MariadbQueryService
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 5000;

    /**
     * How long one statement may run on one server (MariaDB's max_statement_time; MySQL's
     * max_execution_time, which only covers SELECT).
     */
    public const TIMEOUT_SECONDS = 30;

    public const MAX_STATEMENT = 100000;

    /**
     * The longest cell shown; longer values are cut.
     */
    public const MAX_CELL = 2000;

    public const AUTH_FAILURES = 5;

    public const AUTH_WINDOW_MINUTES = 15;

    public const THROTTLED = 'Too many refused database logins: wait ' . self::AUTH_WINDOW_MINUTES . ' minutes and try again.';

    /**
     * MySQL/MariaDB errors that mean the login itself was refused.
     */
    private const AUTH_ERRORS = [1045, 1698];

    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly ClockInterface $clock = new SystemClock(),
        private ?QueryAccountService $accounts = null,
    ) {
    }

    /**
     * Whether a statement only reads: its first word (after comments) is SELECT, SHOW, DESCRIBE, DESC,
     * EXPLAIN, WITH, VALUES or TABLE. Anything else may change data and needs confirming.
     */
    public static function isRead(string $sql): bool
    {
        $sql = (string) preg_replace('~^(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|/\*.*?\*/)*~s', '', $sql);

        return preg_match('/^(select|show|describe|desc|explain|with|values|table)\b/i', $sql) === 1;
    }

    /**
     * The servers the tool can run on: those with MariaDB/MySQL monitoring, by name.
     *
     * @return list<Server>
     */
    public function servers(): array
    {
        /** @var list<Server> $servers */
        $servers = Server::query()->where('mysql_enabled', true)->orderBy('name')->get()->all();

        return array_values($servers);
    }

    /**
     * Run a statement on the chosen servers, each with the login chosen for it: one of the user's own
     * accounts that's for that server (QueryAccountService), or (admins only) "stored", the server's
     * stored account. When an account's login is refused by the first server it's tried on in this run,
     * it isn't tried on the rest.
     *
     * @param array<int, int|string> $plan server id => account id, or "stored"
     * @return array{
     *     columns: list<string>,
     *     rows: list<array{server: string, values: array<string, ?string>}>,
     *     servers: list<array{name: string, ok: bool, message: string, rows: int, affected: ?int, ms: int, truncated: bool, login: string}>,
     *     auth_failed: bool,
     *     writes: bool
     * }
     *
     * @throws DomainException with a user-facing message when nothing was run
     * @throws AuthorizationException when a non-admin asks for stored accounts
     */
    public function run(User $user, array $plan, ?string $database, string $sql, int $limit = self::DEFAULT_LIMIT, bool $confirmedWrites = false): array
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\r\n");
        $database = trim((string) $database) ?: null;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $writes = !self::isRead($sql);

        if ($sql === '') {
            throw new DomainException('Type a statement to run.');
        }

        if (strlen($sql) > self::MAX_STATEMENT) {
            throw new DomainException('The statement is too long (' . number_format(self::MAX_STATEMENT) . ' characters at most).');
        }

        if ($database !== null && preg_match('/^[\w$-]{1,64}$/u', $database) !== 1) {
            throw new DomainException('That isn\'t a database name.');
        }

        $accounts = $this->accounts ??= new QueryAccountService($this->mysql, clock: $this->clock);
        $own = [];

        foreach ($accounts->forUser($user) as $account) {
            $own[$account->id] = $account;
        }

        // Each server with its login, checked: the user's own account for that server, or stored (admins).
        $steps = [];

        foreach ($this->servers() as $server) {
            $choice = $plan[$server->id] ?? null;

            if ($choice === null || $choice === '') {
                continue;
            }

            if ($choice === 'stored') {
                if (!$user->isAdmin()) {
                    throw new AuthorizationException('Only admins can query with the servers\' stored accounts.');
                }

                $steps[] = ['server' => $server, 'account' => null];

                continue;
            }

            $account = $own[(int) $choice] ?? null;

            if (!$account instanceof QueryAccount || !$account->isFor($server)) {
                throw new DomainException("Choose one of your accounts for {$server->name}.");
            }

            $steps[] = ['server' => $server, 'account' => $account];
        }

        if ($steps === []) {
            throw new DomainException('Choose at least one server.');
        }

        if ($writes && !$confirmedWrites) {
            throw new DomainException('This statement isn\'t a plain read, so it may change data on ' . count($steps) . ' ' . (count($steps) === 1 ? 'server' : 'servers') . ': tick the box to confirm.');
        }

        $this->requireNotThrottled($user);
        $results = [];
        $refusedAccounts = [];
        $triedAccounts = [];
        $authFailed = false;

        foreach ($steps as ['server' => $server, 'account' => $account]) {
            $login = $account === null ? 'stored account' : $account->label;

            if ($account !== null && isset($refusedAccounts[$account->id])) {
                $results[] = ['server' => $server, 'ok' => false, 'message' => "Not tried: {$refusedAccounts[$account->id]} refused this login first.", 'rows' => [], 'columns' => [], 'affected' => null, 'ms' => 0, 'truncated' => false, 'login' => $login];

                continue;
            }

            $result = $this->runOn($server, $account === null ? null : [$account->username, $accounts->password($account)], $database, $sql, $limit);
            $result['login'] = $login;
            $results[] = $result;

            if ($account !== null) {
                // An account refused by the first server it meets in this run isn't tried on the others.
                if ($result['auth'] && !isset($triedAccounts[$account->id])) {
                    $refusedAccounts[$account->id] = $server->name;
                    // Only a wrong password counts toward the refused-login limit (see QueryAccountService).
                    $authFailed = $authFailed || !empty($result['guess']);
                }

                $triedAccounts[$account->id] = true;
                $account->last_used_at = Carbon::instance($this->clock->now());
                $account->save();
            }
        }

        $collated = $this->collate($results);
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        $logins = array_values(array_unique(array_map(fn ($r) => $r['login'], $results)));
        MariadbQuery::query()->create([
            'user_id' => $user->id,
            'db_user' => mb_substr(implode(', ', $logins), 0, 255),
            'servers' => array_map(fn ($step) => $step['server']->id, $steps),
            'statement' => $sql,
            'writes' => $writes,
            'outcome' => "$ok ok, " . (count($results) - $ok) . ' failed',
            'auth_failed' => $authFailed,
            // The service's clock (the throttle counts by it).
            'created_at' => Carbon::instance($this->clock->now()),
        ]);

        return $collated + ['auth_failed' => $authFailed, 'writes' => $writes];
    }

    /**
     * Refuses when the user has had AUTH_FAILURES refused database logins within AUTH_WINDOW_MINUTES
     * (queries and account checks alike): guessing passwords through the tool stays slow.
     *
     * @throws DomainException
     */
    public function requireNotThrottled(User $user): void
    {
        if ($this->throttled($user)) {
            throw new DomainException(self::THROTTLED);
        }
    }

    /**
     * Whether the user has had too many refused logins lately (see requireNotThrottled()).
     */
    public function throttled(User $user): bool
    {
        return $this->recentRefusals($user)->count() >= self::AUTH_FAILURES;
    }

    /**
     * Lift an admin's own refused-login wait: their recent refusals stop counting (the log rows stay,
     * no longer marked as refused), and the clearing is logged.
     *
     * @return int how many refusals were cleared
     *
     * @throws AuthorizationException for anyone but an admin
     */
    public function clearThrottle(User $admin): int
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can clear the wait.');
        }

        $cleared = $this->recentRefusals($admin)->update(['auth_failed' => false]);
        MariadbQuery::query()->create([
            'user_id' => $admin->id, 'db_user' => '', 'servers' => [], 'statement' => '(refused-login wait cleared)',
            'writes' => false, 'outcome' => "$cleared refused " . ($cleared === 1 ? 'login' : 'logins') . ' cleared',
            'auth_failed' => false, 'created_at' => Carbon::instance($this->clock->now()),
        ]);

        return $cleared;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<MariadbQuery>
     */
    private function recentRefusals(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $since = Carbon::instance($this->clock->now())->subMinutes(self::AUTH_WINDOW_MINUTES);

        return MariadbQuery::query()->where('user_id', $user->id)->where('auth_failed', true)->where('created_at', '>=', $since);
    }

    /**
     * @return array{server: Server, ok: bool, auth: bool, guess: bool, message: string, columns: list<string>, rows: list<list<?string>>, affected: ?int, ms: int, truncated: bool}
     */
    /**
     * @param array{0: string, 1: string}|null $login username and password; null: the server's stored account
     */
    private function runOn(Server $server, ?array $login, ?string $database, string $sql, int $limit): array
    {
        $result = ['server' => $server, 'ok' => false, 'auth' => false, 'guess' => false, 'message' => '', 'columns' => [], 'rows' => [], 'affected' => null, 'ms' => 0, 'truncated' => false];
        // Timed from the login: a slow or unreachable server spends its time there.
        $start = hrtime(true);

        try {
            $pdo = $login === null ? $this->mysql->connect($server, $database) : $this->mysql->connectAs($server, $login[0], $login[1], $database);
        } catch (ServerConnectionException $e) {
            $code = QueryAccountService::loginError($e);
            $result['auth'] = in_array($code, self::AUTH_ERRORS, true);
            $result['guess'] = $code === 1045;
            $result['message'] = $e->getMessage() . ($code === 1698 ? QueryAccountService::NO_PASSWORD_HINT : '');
            $result['ms'] = (int) round((hrtime(true) - $start) / 1e6);

            return $result;
        }

        // A time limit for the statement: MariaDB, then MySQL (SELECT only); each server ignores the other's.
        foreach (['SET SESSION max_statement_time = ' . self::TIMEOUT_SECONDS, 'SET SESSION max_execution_time = ' . (self::TIMEOUT_SECONDS * 1000)] as $setting) {
            try {
                $pdo->exec($setting);
            } catch (PDOException) {
                // not this server's variable
            }
        }

        try {
            $statement = $pdo->query($sql);

            if ($statement === false) {
                throw new PDOException('The statement failed.');
            }

            $count = $statement->columnCount();

            if ($count > 0) {
                for ($c = 0; $c < $count; $c++) {
                    $meta = $statement->getColumnMeta($c);
                    $result['columns'][] = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : "column $c";
                }

                while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                    if (count($result['rows']) >= $limit) {
                        $result['truncated'] = true;

                        break;
                    }

                    $result['rows'][] = array_map(fn ($value) => $this->cell($value), $row);
                }

                $statement->closeCursor();
            } else {
                $result['affected'] = $statement->rowCount();
            }

            $result['ok'] = true;
            $result['message'] = $count > 0
                ? count($result['rows']) . ' ' . (count($result['rows']) === 1 ? 'row' : 'rows') . ($result['truncated'] ? " (the first $limit)" : '')
                : $result['affected'] . ' ' . ($result['affected'] === 1 ? 'row' : 'rows') . ' affected';
        } catch (PDOException $e) {
            $result['message'] = 'The statement failed: ' . $e->getMessage();
        } finally {
            $result['ms'] = (int) round((hrtime(true) - $start) / 1e6);
        }

        return $result;
    }

    /**
     * One table from every server's rows: a Server column, then the columns in the order they first
     * appear (a name repeated within one result gets " (2)" and so on).
     *
     * @param list<array{server: Server, ok: bool, message: string, columns: list<string>, rows: list<list<?string>>, affected: ?int, ms: int, truncated: bool, ...}> $results
     * @return array{columns: list<string>, rows: list<array{server: string, values: array<string, ?string>}>, servers: list<array{name: string, ok: bool, message: string, rows: int, affected: ?int, ms: int, truncated: bool, login: string}>}
     */
    private function collate(array $results): array
    {
        $columns = [];
        $rows = [];
        $servers = [];

        foreach ($results as $result) {
            $names = [];

            foreach ($result['columns'] as $name) {
                $unique = $name;

                for ($n = 2; in_array($unique, $names, true); $n++) {
                    $unique = "$name ($n)";
                }

                $names[] = $unique;

                if (!in_array($unique, $columns, true)) {
                    $columns[] = $unique;
                }
            }

            foreach ($result['rows'] as $row) {
                $rows[] = ['server' => $result['server']->name, 'values' => array_combine($names, $row)];
            }

            $servers[] = [
                'name' => $result['server']->name, 'ok' => $result['ok'], 'message' => $result['message'], 'rows' => count($result['rows']),
                'affected' => $result['affected'], 'ms' => $result['ms'], 'truncated' => $result['truncated'], 'login' => (string) ($result['login'] ?? ''),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'servers' => $servers];
    }

    private function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? (string) $value : (is_resource($value) ? (string) stream_get_contents($value) : '');

        // Binary data (not UTF-8) as hex.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = '0x' . bin2hex(substr($text, 0, intdiv(self::MAX_CELL, 2)));
        }

        return mb_strlen($text) > self::MAX_CELL ? mb_substr($text, 0, self::MAX_CELL) . '…' : $text;
    }
}
