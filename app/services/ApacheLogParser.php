<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeZone;
use Throwable;

/**
 * Apache's log files: error log entries (2.4 and 2.2 formats; times are in
 * the server's timezone) and access log lines in any format built on the
 * common log format's core, `[time] "request" status bytes`, which covers
 * common, combined and the vhost_* variants.
 */
class ApacheLogParser
{
    public const MAX_MESSAGE = 8000;

    /**
     * Error log levels kept; info, debug and trace are too many to be useful.
     */
    private const LEVELS = [
        'emerg' => 'error', 'alert' => 'error', 'crit' => 'error', 'error' => 'error',
        'warn' => 'warning', 'notice' => 'note',
    ];

    /**
     * @return \Generator<int, array{time: Carbon, level: string, message: string}>
     */
    public function errorEntries(string $text, DateTimeZone $zone): \Generator
    {
        $pending = null;
        $skipping = false;

        foreach ($this->lines($text) as $line) {
            // [Tue Sep 29 15:11:38.055668 2026] [module:level] [pid 1:tid 2] [client 1.2.3.4:5] AH00000: message
            if (preg_match('/^\[(\w{3} \w{3} +\d{1,2} \d{2}:\d{2}:\d{2}(?:\.\d+)? \d{4})\] \[(?:[\w.-]+:)?(\w+)\] (.*)$/', $line, $m) === 1) {
                if ($pending !== null) {
                    yield $pending;
                }

                $pending = null;
                $level = self::LEVELS[strtolower($m[2])] ?? null;
                $skipping = $level === null;
                $time = $this->errorTime($m[1], $zone);

                if ($level === null || $time === null) {
                    continue;
                }

                // Drop the pid/tid and client prefixes: "[pid 12] [client 1.2.3.4:5] message".
                $message = trim((string) preg_replace('/^(?:\[(?:pid|client|remote) [^\]]*\] )+/', '', $m[3]));

                if (preg_match('/\bexit signal\b|\bSegmentation fault\b/i', $message) === 1) {
                    $level = 'crash';
                }

                $pending = ['time' => $time, 'level' => $level, 'message' => mb_substr($message, 0, self::MAX_MESSAGE)];
            } elseif ($pending !== null && !$skipping && trim($line) !== '' && mb_strlen($pending['message']) < self::MAX_MESSAGE) {
                // A line without a timestamp continues the entry above (e.g. a PHP stack trace).
                $pending['message'] = mb_substr($pending['message'] . "\n" . rtrim($line), 0, self::MAX_MESSAGE);
            }
        }

        if ($pending !== null) {
            yield $pending;
        }
    }

    /**
     * One entry per request line; lines in other formats are skipped (see $skipped).
     *
     * @param int $skipped lines that didn't match
     * @return \Generator<int, array{time: int, status: int, bytes: int}> time as a unix timestamp
     */
    public function accessLines(string $text, int &$skipped = 0): \Generator
    {
        foreach ($this->lines($text) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('#\[(\d{2}/\w{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\] "(?:[^"\\\\]|\\\\.)*" (\d{3}) (\d+|-)#', $line, $m) !== 1) {
                $skipped++;

                continue;
            }

            $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[1]);

            if ($time === false) {
                $skipped++;

                continue;
            }

            yield ['time' => $time->getTimestamp(), 'status' => (int) $m[2], 'bytes' => $m[3] === '-' ? 0 : (int) $m[3]];
        }
    }

    private function errorTime(string $value, DateTimeZone $zone): ?Carbon
    {
        $value = (string) preg_replace('/ +/', ' ', $value);

        foreach (['D M d H:i:s.u Y', 'D M j H:i:s.u Y', 'D M d H:i:s Y', 'D M j H:i:s Y'] as $format) {
            try {
                $time = Carbon::createFromFormat($format, $value, $zone);

                if ($time !== null) {
                    return $time->utc();
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        return null;
    }

    /**
     * @return \Generator<int, string>
     */
    private function lines(string $text): \Generator
    {
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $end = strpos($text, "\n", $offset);
            $end = $end === false ? $length : $end;
            yield rtrim(substr($text, $offset, $end - $offset), "\r");
            $offset = $end + 1;
        }
    }
}
