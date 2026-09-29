<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;
use PDOException;

/**
 * Crashed or unreadable tables, in two cheap passes:
 *
 * 1. information_schema.TABLES: a table MariaDB can't open has no engine and
 *    the error in TABLE_COMMENT (e.g. "... is marked as crashed and should
 *    be repaired"). This covers every engine.
 * 2. CHECK TABLE ... FAST QUICK on MyISAM and Aria tables only: FAST skips
 *    tables that were closed properly, so only suspect tables are examined.
 *    InnoDB doesn't mark tables as crashed and isn't checked this way.
 */
class CrashedTablesCheck extends MariaDbCheck
{
    public const CHECKED_ENGINES = ['myisam', 'aria'];

    private const TABLES_PER_CHECK = 50;

    private const SKIPPED_SCHEMAS = ['information_schema', 'performance_schema', 'sys'];

    private const OK_MESSAGES = ['ok', 'table is already up to date'];

    public function key(): string
    {
        return 'crashed_tables';
    }

    public function label(): string
    {
        return 'Crashed tables';
    }

    public function run(PDO $pdo): CheckResult
    {
        $skipped = implode(', ', array_map(fn (string $schema) => $pdo->quote($schema), self::SKIPPED_SCHEMAS));
        $tables = $pdo->query(
            "SELECT TABLE_SCHEMA AS db, TABLE_NAME AS name, ENGINE AS engine, TABLE_COMMENT AS comment
             FROM information_schema.TABLES
             WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA NOT IN ($skipped)",
        )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $checkable = array_values(array_filter($tables, fn (array $t) => in_array(strtolower((string) $t['engine']), self::CHECKED_ENGINES, true)));
        $checkRows = [];

        foreach (array_chunk($checkable, self::TABLES_PER_CHECK) as $chunk) {
            $names = implode(', ', array_map(fn (array $t) => $this->quoteName($t['db']) . '.' . $this->quoteName($t['name']), $chunk));

            try {
                array_push($checkRows, ...($pdo->query("CHECK TABLE $names FAST QUICK")?->fetchAll(PDO::FETCH_ASSOC) ?: []));
            } catch (PDOException $e) {
                return $this->result(HealthStatus::Unknown, "CHECK TABLE failed: {$e->getMessage()}. The monitoring user needs SELECT on the tables.");
            }
        }

        return $this->evaluate($tables, $checkRows, count($checkable));
    }

    /**
     * @param list<array{db: string, name: string, engine: ?string, comment: ?string}> $tables
     * @param list<array<string, mixed>> $checkRows CHECK TABLE output: Table, Op, Msg_type, Msg_text
     */
    public function evaluate(array $tables, array $checkRows, int $checkedCount): CheckResult
    {
        $problems = [];
        $warnings = [];
        $denied = 0;

        foreach ($tables as $table) {
            if ($table['engine'] === null && trim((string) $table['comment']) !== '') {
                $problems["{$table['db']}.{$table['name']}"] = trim((string) $table['comment']);
            }
        }

        foreach ($checkRows as $row) {
            $name = (string) ($row['Table'] ?? '');
            $type = strtolower((string) ($row['Msg_type'] ?? ''));
            $text = trim((string) ($row['Msg_text'] ?? ''));

            if (stripos($text, 'command denied') !== false) {
                $denied++;
            } elseif ($type === 'error' || ($type === 'status' && !in_array(strtolower($text), self::OK_MESSAGES, true))) {
                $problems[$name] ??= $text;
            } elseif ($type === 'warning') {
                $warnings[$name] ??= $text;
            }
        }

        $scope = count($tables) . ' tables, ' . $checkedCount . ' MyISAM/Aria checked';
        $details = ['problems' => $problems, 'warnings' => $warnings, 'tables' => count($tables), 'checked' => $checkedCount];

        if ($problems !== []) {
            $list = implode('; ', array_map(fn ($name, $text) => "$name ($text)", array_keys($problems), $problems));

            return $this->result(HealthStatus::Critical, count($problems) . " crashed or unreadable table(s): $list. Repair with REPAIR TABLE or mariadb-check --repair.", count($problems), 'tables', $details);
        }

        if ($warnings !== []) {
            return $this->result(HealthStatus::Warning, count($warnings) . ' table(s) with warnings: ' . implode(', ', array_keys($warnings)) . ". ($scope)", 0, 'tables', $details);
        }

        if ($denied > 0) {
            return $this->result(HealthStatus::Unknown, "$denied table(s) couldn't be checked: the monitoring user needs SELECT on them. ($scope)", 0, 'tables', $details);
        }

        return $this->result(HealthStatus::Ok, "No crashed tables. ($scope)", 0, 'tables', $details);
    }

    private function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
