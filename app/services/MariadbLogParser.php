<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeZone;
use Throwable;

/**
 * Turns MariaDB (and MySQL) log text into entries: time (UTC), level and
 * message. Times without a zone are read in the server's own timezone.
 *
 * Levels: note, warning, error, crash (the server died: "got signal" or
 * "got exception"; the stack trace that follows is part of the message) and
 * slow (a slow query).
 */
class MariadbLogParser
{
    public const MAX_MESSAGE = 8000;

    private const LEVELS = ['note' => 'note', 'system' => 'note', 'warning' => 'warning', 'error' => 'error'];

    /**
     * An error log file, or the systemd journal's lines for the service
     * (`journalctl -o short-iso`), which wrap the same messages.
     *
     * @return list<array{time: Carbon, level: string, message: string}>
     */
    public function parseErrorLog(string $text, DateTimeZone $zone): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with($line, '-- ')) {
                continue; // blank, or a journalctl banner ("-- No entries --", "-- Boot ... --")
            }

            $entry = $this->errorLine($line, $zone);

            if ($entry !== null) {
                $entries[] = $entry;
            } elseif ($entries !== []) {
                // A line without a timestamp continues the entry above (e.g. a stack trace).
                $last = &$entries[count($entries) - 1];
                $last['message'] = mb_substr($last['message'] . "\n" . rtrim($line), 0, self::MAX_MESSAGE);
                unset($last);
            }
        }

        foreach ($entries as &$entry) {
            if (preg_match('/\bgot (signal|exception)\b/i', $entry['message']) === 1) {
                $entry['level'] = 'crash';
            }
        }

        return $entries;
    }

    /**
     * The slow query log: one entry per query, at the time it ran.
     *
     * @return list<array{time: Carbon, level: string, message: string}>
     */
    public function parseSlowLog(string $text, DateTimeZone $zone): array
    {
        $entries = [];
        $time = null;
        $current = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^# Time: (.+)$/', $line, $m) === 1) {
                $entries[] = $this->slowEntry($current, $time);
                $current = null;
                $time = $this->slowLogTime(trim($m[1]), $zone) ?? $time;
            } elseif (preg_match('/^# User@Host: (\S+)/', $line, $m) === 1) {
                $entries[] = $this->slowEntry($current, $time);
                $current = ['sql' => [], 'who' => $m[1], 'time' => null, 'stats' => []];
            } elseif ($current === null) {
                continue; // file header ("Version:", "Tcp port:", "Time Id Command Argument")
            } elseif (str_starts_with($line, '# ')) {
                foreach (['Query_time', 'Rows_sent', 'Rows_examined'] as $field) {
                    if (preg_match('/\b' . $field . ': ([\d.]+)/', $line, $m) === 1) {
                        $current['stats'][$field] = $m[1];
                    }
                }

                if (preg_match('/\bSchema: (\S+)/', $line, $m) === 1) {
                    $current['who'] .= " on {$m[1]}";
                }
            } elseif (preg_match('/^SET timestamp=(\d+);$/', $trimmed, $m) === 1) {
                $current['time'] = Carbon::createFromTimestamp((int) $m[1], 'UTC');
            } elseif ($trimmed !== '' && preg_match('/^use \S+;$/i', $trimmed) !== 1) {
                $current['sql'][] = rtrim($line);
            }
        }

        $entries[] = $this->slowEntry($current, $time);

        return array_values(array_filter($entries));
    }

    /**
     * @param array{sql: list<string>, who: string, time: ?Carbon, stats: array<string, string>}|null $query
     * @return array{time: Carbon, level: string, message: string}|null
     */
    private function slowEntry(?array $query, ?Carbon $time): ?array
    {
        $at = $query['time'] ?? $time;

        if ($query === null || $query['sql'] === [] || $at === null) {
            return null;
        }

        $stats = $query['stats'];
        $summary = implode(', ', array_filter([
            isset($stats['Query_time']) ? 'took ' . rtrim(rtrim(number_format((float) $stats['Query_time'], 3, '.', ''), '0'), '.') . ' s' : null,
            isset($stats['Rows_examined']) ? "{$stats['Rows_examined']} rows examined" : null,
            isset($stats['Rows_sent']) ? "{$stats['Rows_sent']} sent" : null,
            $query['who'],
        ]));

        return [
            'time' => $at,
            'level' => 'slow',
            'message' => mb_substr("$summary: " . trim(implode("\n", $query['sql'])), 0, self::MAX_MESSAGE),
        ];
    }

    /**
     * @return array{time: Carbon, level: string, message: string}|null null for a line without a timestamp
     */
    private function errorLine(string $line, DateTimeZone $zone): ?array
    {
        // Journal: "2026-09-29T14:03:01-0700 host mariadbd[123]: <message>"; its time has a zone, so it wins.
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)) \S+ [^:\s]+: (.*)$/', $line, $m) === 1) {
            $inner = $this->errorLine($m[2], $zone);

            return [
                'time' => $this->time($m[1], null),
                'level' => $inner['level'] ?? 'note',
                'message' => $inner['message'] ?? trim($m[2]),
            ];
        }

        // MariaDB 10.x: "2026-09-29 14:03:01 0 [Note] message" (thread id optional).
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) +(\d{1,2}:\d{2}:\d{2})(?: +\d+)? +\[(\w+)\] ?(.*)$/', $line, $m) === 1) {
            return $this->entry($this->time("$m[1] $m[2]", $zone), $m[3], $m[4]);
        }

        // Older MariaDB / MySQL 5.5: "250929 14:03:01 [Note] message".
        if (preg_match('/^(\d{2})(\d{2})(\d{2}) +(\d{1,2}:\d{2}:\d{2})(?: +\d+)? +\[(\w+)\] ?(.*)$/', $line, $m) === 1) {
            return $this->entry($this->time("20$m[1]-$m[2]-$m[3] $m[4]", $zone), $m[5], $m[6]);
        }

        // MySQL 8: "2026-09-29T14:03:01.123456Z 0 [Warning] [MY-010068] [Server] message".
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})) \d+ \[(\w+)\] (?:\[[^\]]*\] )*(.*)$/', $line, $m) === 1) {
            return $this->entry($this->time($m[1], null), $m[2], $m[3]);
        }

        return null;
    }

    /**
     * @return array{time: Carbon, level: string, message: string}|null
     */
    private function entry(?Carbon $time, string $level, string $message): ?array
    {
        return $time === null ? null : [
            'time' => $time,
            'level' => self::LEVELS[strtolower($level)] ?? 'note',
            'message' => mb_substr(trim($message), 0, self::MAX_MESSAGE),
        ];
    }

    /**
     * "# Time: 250929 14:03:01" (older) or "# Time: 2026-09-29T14:03:01.123456Z" / without zone.
     */
    private function slowLogTime(string $value, DateTimeZone $zone): ?Carbon
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2}) +(\d{1,2}:\d{2}:\d{2})$/', $value, $m) === 1) {
            return $this->time("20$m[1]-$m[2]-$m[3] $m[4]", $zone);
        }

        return $this->time($value, preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1 ? null : $zone);
    }

    /**
     * @param DateTimeZone|null $zone for times without their own zone
     */
    private function time(string $value, ?DateTimeZone $zone): ?Carbon
    {
        try {
            return Carbon::parse($value, $zone)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
