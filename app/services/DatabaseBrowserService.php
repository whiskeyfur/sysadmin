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
 * The MariaDB browser: server → database → table, read only. It logs in the way the query tool does
 * (MariadbQueryService): with one of the user's own accounts for that server (QueryAccountService), or,
 * for admins, the server's stored account, so the database's own privileges decide what can be seen.
 * The session is made read only and only SHOW / SELECT statements are sent; names are checked against
 * information_schema before they're used in a statement.
 *
 * Each level lists the privileges that apply there, from that level and above (server: global grants;
 * database: global + database; table: global + database + table + column), as far as the login can see
 * them: without read access to the mysql database, information_schema shows a login only its own.
 */
class DatabaseBrowserService
{
    public const PAGE_SIZE = 100;

    public const TIMEOUT_SECONDS = 30;

    /**
     * Schemas MariaDB/MySQL keep for themselves (listed, marked as such).
     */
    public const SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        private readonly ClockInterface $clock = new SystemClock(),
        private ?QueryAccountService $accounts = null,
    ) {
    }

    /**
     * @return list<Server>
     */
    public function servers(): array
    {
        return (new MariadbQueryService($this->mysql, $this->clock))->servers();
    }

    /**
     * The logins the user may browse a server with: their own accounts for it, then (admins) "stored".
     *
     * @return list<array{value: string, label: string, refused: bool}>
     */
    public function logins(User $user, Server $server): array
    {
        $logins = [];

        foreach ($this->accounts()->forUser($user) as $account) {
            if ($account->isFor($server)) {
                $logins[] = ['value' => (string) $account->id, 'label' => "{$account->label} ({$account->username})", 'refused' => $account->refusedBy($server) !== null];
            }
        }

        if ($user->isAdmin()) {
            $logins[] = ['value' => 'stored', 'label' => 'Server\'s stored account (admins)', 'refused' => false];
        }

        return $logins;
    }

    /**
     * The login to use when none was chosen: the user's first account that the server hasn't refused,
     * else any of theirs, else (admins) the stored account.
     */
    public function defaultLogin(User $user, Server $server): ?string
    {
        $logins = $this->logins($user, $server);

        foreach ($logins as $login) {
            if (!$login['refused']) {
                return $login['value'];
            }
        }

        return $logins[0]['value'] ?? null;
    }

    /**
     * Log in to a server with the chosen login, read only.
     *
     * @throws DomainException with a user-facing message (not the user's login, refused, unreachable, waiting)
     * @throws AuthorizationException when a non-admin asks for the stored account
     */
    public function connect(User $user, Server $server, string $login): PDO
    {
        if (!$server->mysql_enabled) {
            throw new DomainException('That server has no MariaDB/MySQL set up.');
        }

        $account = null;

        if ($login === 'stored') {
            if (!$user->isAdmin()) {
                throw new AuthorizationException('Only admins can browse with the servers\' stored accounts.');
            }
        } else {
            $account = $this->accounts()->find($user, (int) $login);

            if (!$account->isFor($server)) {
                throw new DomainException("{$account->label} isn't one of your accounts for {$server->name}.");
            }
        }

        $queries = new MariadbQueryService($this->mysql, $this->clock);
        $queries->requireNotThrottled($user);

        try {
            $pdo = $account === null ? $this->mysql->connect($server) : $this->mysql->connectAs($server, $account->username, $this->accounts()->password($account));
        } catch (ServerConnectionException $e) {
            $code = QueryAccountService::loginError($e);

            if ($account instanceof QueryAccount && in_array($code, QueryAccountService::AUTH_ERRORS, true)) {
                // Counted like the query tool's refusals (a wrong password only), so guessing stays slow.
                MariadbQuery::query()->create([
                    'user_id' => $user->id, 'db_user' => mb_substr("{$account->label} ({$account->username})", 0, 255), 'servers' => [$server->id],
                    'statement' => '(browser login)', 'writes' => false, 'outcome' => '0 ok, 1 failed',
                    'auth_failed' => $code === 1045, 'created_at' => Carbon::instance($this->clock->now()),
                ]);
            }

            throw new DomainException($e->getMessage() . ($code === 1698 ? QueryAccountService::NO_PASSWORD_HINT : ''));
        }

        if ($account instanceof QueryAccount) {
            $account->last_used_at = Carbon::instance($this->clock->now());
            $account->save();
        }

        // Read only, with a time limit per statement (MariaDB, then MySQL; each ignores the other's).
        foreach (['SET SESSION TRANSACTION READ ONLY', 'SET SESSION max_statement_time = ' . self::TIMEOUT_SECONDS, 'SET SESSION max_execution_time = ' . (self::TIMEOUT_SECONDS * 1000)] as $setting) {
            try {
                $pdo->exec($setting);
            } catch (PDOException) {
                // not this server's variable
            }
        }

        return $pdo;
    }

    /**
     * The databases the login can see, with their table count and size.
     *
     * @return list<array{name: string, tables: int, bytes: int, collation: string, system: bool}>
     */
    public function schemas(PDO $pdo): array
    {
        $sizes = [];

        foreach ($this->select($pdo, 'SELECT TABLE_SCHEMA AS s, COUNT(*) AS n, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS b FROM information_schema.TABLES GROUP BY TABLE_SCHEMA') as $row) {
            $sizes[(string) $row['s']] = [(int) $row['n'], (int) $row['b']];
        }

        $schemas = [];

        foreach ($this->select($pdo, 'SELECT SCHEMA_NAME AS name, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME') as $row) {
            $name = (string) $row['name'];
            $schemas[] = [
                'name' => $name, 'tables' => $sizes[$name][0] ?? 0, 'bytes' => $sizes[$name][1] ?? 0,
                'collation' => (string) $row['collation'], 'system' => in_array(strtolower($name), self::SYSTEM_SCHEMAS, true),
            ];
        }

        return $schemas;
    }

    /**
     * A database's tables and views.
     *
     * @return list<array{name: string, type: string, engine: ?string, rows: ?int, bytes: int, collation: ?string, comment: string}>
     *
     * @throws DomainException when the login can't see that database
     */
    public function tables(PDO $pdo, string $schema): array
    {
        $this->requireSchema($pdo, $schema);
        $tables = [];

        foreach ($this->select($pdo, 'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS row_count, COALESCE(DATA_LENGTH + INDEX_LENGTH, 0) AS bytes, TABLE_COLLATION AS collation, TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$schema]) as $row) {
            $tables[] = [
                'name' => (string) $row['name'], 'type' => (string) $row['type'], 'engine' => $row['engine'] === null ? null : (string) $row['engine'],
                'rows' => $row['row_count'] === null ? null : (int) $row['row_count'], 'bytes' => (int) $row['bytes'],
                'collation' => $row['collation'] === null ? null : (string) $row['collation'], 'comment' => (string) $row['comment'],
            ];
        }

        return $tables;
    }

    /**
     * A table's (or view's) type and its CREATE statement as the server gives it.
     *
     * @return array{type: string, create: string, rows: ?int}
     *
     * @throws DomainException when the login can't see it or may not read its definition
     */
    public function table(PDO $pdo, string $schema, string $table): array
    {
        $info = $this->requireTable($pdo, $schema, $table);

        try {
            $row = $pdo->query('SHOW CREATE TABLE ' . $this->quote($schema) . '.' . $this->quote($table))?->fetch(PDO::FETCH_NUM);
            $create = is_array($row) ? (string) ($row[1] ?? '') : '';
        } catch (PDOException $e) {
            $create = '-- The server wouldn\'t show the definition: ' . $e->getMessage();
        }

        return ['type' => $info['type'], 'create' => $create, 'rows' => $info['rows']];
    }

    /**
     * The table's columns, in order.
     *
     * @return list<array{name: string, type: string, key: string, nullable: bool}>
     */
    public function columns(PDO $pdo, string $schema, string $table): array
    {
        $columns = [];

        foreach ($this->select($pdo, 'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_KEY AS col_key, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schema, $table]) as $row) {
            $columns[] = ['name' => (string) $row['name'], 'type' => (string) $row['type'], 'key' => (string) $row['col_key'], 'nullable' => $row['nullable'] === 'YES'];
        }

        return $columns;
    }

    /**
     * One page of a table's rows: searched (any column containing the text), sorted by a column.
     *
     * @return array{columns: list<string>, rows: list<list<?string>>, total: int, estimated: bool, page: int, pages: int}
     *
     * @throws DomainException with the server's error
     */
    public function rows(PDO $pdo, string $schema, string $table, int $page = 1, string $search = '', string $sort = '', string $direction = 'asc'): array
    {
        $info = $this->requireTable($pdo, $schema, $table);
        $columns = array_map(fn ($c) => $c['name'], $this->columns($pdo, $schema, $table));

        if ($columns === []) {
            throw new DomainException('This login can\'t read any of the table\'s columns.');
        }

        $from = ' FROM ' . $this->quote($schema) . '.' . $this->quote($table);
        $where = '';
        $params = [];

        if ($search !== '') {
            $where = ' WHERE CONCAT_WS(CHAR(31), ' . implode(', ', array_map(fn ($c) => $this->quote($c), $columns)) . ') LIKE ?';
            $params[] = '%' . addcslashes($search, '\\%_') . '%';
        }

        $estimated = false;

        try {
            $total = (int) $this->select($pdo, 'SELECT COUNT(*) AS n' . $from . $where, $params)[0]['n'];
        } catch (DomainException $e) {
            if ($search !== '' || $info['rows'] === null) {
                throw $e;
            }

            // A very large table can take longer to count than a statement may run: the server's estimate.
            $total = $info['rows'];
            $estimated = true;
        }

        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $order = in_array($sort, $columns, true) ? ' ORDER BY ' . $this->quote($sort) . ($direction === 'desc' ? ' DESC' : ' ASC') : '';
        $sql = 'SELECT ' . implode(', ', array_map(fn ($c) => $this->quote($c), $columns)) . $from . $where . $order
            . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE);
        $rows = [];

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $rows[] = array_map(fn ($value) => MariadbQueryService::cell($value), $row);
            }
        } catch (PDOException $e) {
            throw new DomainException('The server refused to read the table: ' . $e->getMessage());
        }

        return ['columns' => $columns, 'rows' => $rows, 'total' => $total, 'estimated' => $estimated, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Who has privileges at this level and above: global grants (always), the database's (when
     * $schema; grants on a pattern such as `shop\_%` count where it matches), the table's and its
     * columns' (when $table). USAGE alone is no privilege and is left out.
     *
     * @return list<array{grantee: string, level: string, on: string, privileges: list<string>, grantable: bool}>
     */
    public function privileges(PDO $pdo, ?string $schema = null, ?string $table = null): array
    {
        $grants = [];
        $add = function (string $grantee, string $level, string $on, string $privilege, string $grantable) use (&$grants) {
            $key = "$grantee|$level|$on";
            $grants[$key] ??= ['grantee' => $grantee, 'level' => $level, 'on' => $on, 'privileges' => [], 'grantable' => false];
            $grants[$key]['privileges'][] = $privilege;
            $grants[$key]['grantable'] = $grants[$key]['grantable'] || $grantable === 'YES';
        };

        foreach ($this->select($pdo, 'SELECT GRANTEE AS g, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS o FROM information_schema.USER_PRIVILEGES') as $row) {
            if ($row['p'] !== 'USAGE' || $row['o'] === 'YES') {
                $add((string) $row['g'], 'server', '*.*', (string) $row['p'], (string) $row['o']);
            }
        }

        if ($schema !== null) {
            foreach ($this->select($pdo, 'SELECT GRANTEE AS g, TABLE_SCHEMA AS s, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS o FROM information_schema.SCHEMA_PRIVILEGES') as $row) {
                if (self::schemaMatches((string) $row['s'], $schema)) {
                    $add((string) $row['g'], 'database', $this->quote((string) $row['s']) . '.*', (string) $row['p'], (string) $row['o']);
                }
            }
        }

        if ($schema !== null && $table !== null) {
            foreach ($this->select($pdo, 'SELECT GRANTEE AS g, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS o FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, $table]) as $row) {
                $add((string) $row['g'], 'table', $this->quote($schema) . '.' . $this->quote($table), (string) $row['p'], (string) $row['o']);
            }

            foreach ($this->select($pdo, 'SELECT GRANTEE AS g, COLUMN_NAME AS c, PRIVILEGE_TYPE AS p, IS_GRANTABLE AS o FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, $table]) as $row) {
                $add((string) $row['g'], 'column', $this->quote((string) $row['c']), (string) $row['p'], (string) $row['o']);
            }
        }

        $order = ['server' => 0, 'database' => 1, 'table' => 2, 'column' => 3];
        $grants = array_values($grants);
        usort($grants, fn ($a, $b) => [$a['grantee'], $order[$a['level']], $a['on']] <=> [$b['grantee'], $order[$b['level']], $b['on']]);

        return $grants;
    }

    /**
     * Whether a database-level grant's name (which may be a LIKE pattern: % and _, with \_ and \% for
     * the characters themselves) covers a database.
     */
    public static function schemaMatches(string $pattern, string $schema): bool
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        // Database names compare case-insensitively on most servers (lower_case_table_names); be generous.
        return preg_match('/^' . $regex . '$/isu', $schema) === 1;
    }

    /**
     * @throws DomainException
     */
    private function requireSchema(PDO $pdo, string $schema): void
    {
        if ($this->select($pdo, 'SELECT 1 AS x FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$schema]) === []) {
            throw new DomainException("There's no database called $schema that this login can see.");
        }
    }

    /**
     * @return array{type: string, rows: ?int}
     *
     * @throws DomainException
     */
    private function requireTable(PDO $pdo, string $schema, string $table): array
    {
        $row = $this->select($pdo, 'SELECT TABLE_TYPE AS type, TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, $table])[0] ?? null;

        if ($row === null) {
            throw new DomainException("There's no table $schema.$table that this login can see.");
        }

        return ['type' => (string) $row['type'], 'rows' => $row['row_count'] === null ? null : (int) $row['row_count']];
    }

    /**
     * @param list<string> $params
     * @return list<array<string, mixed>>
     *
     * @throws DomainException with the server's error
     */
    private function select(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $e) {
            throw new DomainException('The server refused: ' . $e->getMessage());
        }
    }

    private function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function accounts(): QueryAccountService
    {
        return $this->accounts ??= new QueryAccountService($this->mysql, clock: $this->clock);
    }
}
