<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbQuery;
use App\Models\Server;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;

/**
 * The MariaDB multi-server query tool: one statement, run on every server chosen, with the user's own
 * database login, and the results collated into one table with a Server column first. Admins may use
 * each server's stored account instead (the monitoring login, often far more privileged than a
 * developer should have); other users never can. Any statement is allowed (the database's own privileges decide); one that isn't a
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

    /**
     * MySQL/MariaDB errors that mean the login itself was refused.
     */
    private const AUTH_ERRORS = [1045, 1698];

    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly ClockInterface $clock = new SystemClock(),
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
     * Run a statement on the chosen servers, as $username/$password, or (admins, $stored) as each
     * server's stored account.
     *
     * @param list<int> $serverIds
     * @return array{
     *     columns: list<string>,
     *     rows: list<array{server: string, values: array<string, ?string>}>,
     *     servers: list<array{name: string, ok: bool, message: string, rows: int, affected: ?int, ms: int, truncated: bool}>,
     *     auth_failed: bool,
     *     writes: bool
     * }
     *
     * @throws DomainException with a user-facing message when nothing was run
     */
    public function run(User $user, array $serverIds, string $username, string $password, ?string $database, string $sql, int $limit = self::DEFAULT_LIMIT, bool $confirmedWrites = false, bool $stored = false): array
    {
        if ($stored && !$user->isAdmin()) {
            throw new AuthorizationException('Only admins can query with the servers\' stored accounts.');
        }

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

        if (!$stored && trim($username) === '') {
            throw new DomainException('Log in with your database username and password first.');
        }

        if ($database !== null && preg_match('/^[\w$-]{1,64}$/u', $database) !== 1) {
            throw new DomainException('That isn\'t a database name.');
        }

        $servers = array_values(array_filter($this->servers(), fn (Server $s) => in_array($s->id, $serverIds, true)));

        if ($servers === []) {
            throw new DomainException('Choose at least one server.');
        }

        if ($writes && !$confirmedWrites) {
            throw new DomainException('This statement isn\'t a plain read, so it may change data on ' . count($servers) . ' ' . (count($servers) === 1 ? 'server' : 'servers') . ': tick the box to confirm.');
        }

        $since = Carbon::instance($this->clock->now())->subMinutes(self::AUTH_WINDOW_MINUTES);

        if (MariadbQuery::query()->where('user_id', $user->id)->where('auth_failed', true)->where('created_at', '>=', $since)->count() >= self::AUTH_FAILURES) {
            throw new DomainException('Too many refused database logins: wait ' . self::AUTH_WINDOW_MINUTES . ' minutes and try again.');
        }

        $results = [];
        $authFailed = false;

        foreach ($servers as $i => $server) {
            if ($authFailed) {
                $results[] = ['server' => $server, 'ok' => false, 'message' => 'Not tried: the first server refused the login.', 'rows' => [], 'columns' => [], 'affected' => null, 'ms' => 0, 'truncated' => false];

                continue;
            }

            $result = $this->runOn($server, $stored ? null : [$username, $password], $database, $sql, $limit);
            // Each stored account is different: one refusing doesn't say anything about the others.
            $authFailed = !$stored && $i === 0 && $result['auth'];
            $results[] = $result;
        }

        $collated = $this->collate($results);
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        MariadbQuery::query()->create([
            'user_id' => $user->id,
            'db_user' => $stored ? 'stored accounts' : mb_substr($username, 0, 255),
            'servers' => array_map(fn (Server $s) => $s->id, $servers),
            'statement' => $sql,
            'writes' => $writes,
            'outcome' => "$ok ok, " . (count($results) - $ok) . ' failed',
            'auth_failed' => $authFailed,
            // The service's clock (the throttle above counts by it).
            'created_at' => Carbon::instance($this->clock->now()),
        ]);

        return $collated + ['auth_failed' => $authFailed, 'writes' => $writes];
    }

    /**
     * @return array{server: Server, ok: bool, auth: bool, message: string, columns: list<string>, rows: list<list<?string>>, affected: ?int, ms: int, truncated: bool}
     */
    /**
     * @param array{0: string, 1: string}|null $login username and password; null: the server's stored account
     */
    private function runOn(Server $server, ?array $login, ?string $database, string $sql, int $limit): array
    {
        $result = ['server' => $server, 'ok' => false, 'auth' => false, 'message' => '', 'columns' => [], 'rows' => [], 'affected' => null, 'ms' => 0, 'truncated' => false];
        // Timed from the login: a slow or unreachable server spends its time there.
        $start = hrtime(true);

        try {
            $pdo = $login === null ? $this->mysql->connect($server, $database) : $this->mysql->connectAs($server, $login[0], $login[1], $database);
        } catch (ServerConnectionException $e) {
            $previous = $e->getPrevious();
            $code = $previous instanceof PDOException ? (int) ($previous->errorInfo[1] ?? 0) : 0;
            $result['auth'] = in_array($code, self::AUTH_ERRORS, true);
            $result['message'] = $e->getMessage();
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
     * @return array{columns: list<string>, rows: list<array{server: string, values: array<string, ?string>}>, servers: list<array{name: string, ok: bool, message: string, rows: int, affected: ?int, ms: int, truncated: bool}>}
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
                'affected' => $result['affected'], 'ms' => $result['ms'], 'truncated' => $result['truncated'],
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
