<?php

namespace App\Services;

/**
 * One access log's LogFormat (e.g. `%h %l %u %t "%r" %>s %O "%{Referer}i" "%{User-Agent}i"`), turned
 * into a parser for its lines: each server and virtual host may log in its own format, so every log is
 * read with the one its CustomLog names (see ApacheService::logFormats()).
 *
 * Fields it knows are captured (client, time, request or its parts, status, size, referer, user agent,
 * virtual host, port, time taken, mod_unique_id's request ID); anything else in the format is matched
 * and ignored.
 */
class AccessLogFormat
{
    /**
     * Apache's default when nothing says otherwise (Common Log Format).
     */
    public const COMMON = '%h %l %u %t "%r" %>s %b';

    private readonly string $regex;

    /**
     * @var array<string, string> capture group => what it is
     */
    private array $groups = [];

    /**
     * How %{...}t was written, when it isn't the standard [day/month/year:time zone].
     */
    private ?string $timeFormat = null;

    public function __construct(public readonly string $format)
    {
        $this->regex = '~^' . $this->compile($format) . '$~';
    }

    /**
     * The fields of one line; null if it isn't in this format.
     *
     * @return array{time: int, status: int, bytes: int, client: ?string, vhost: ?string, method: ?string, path: ?string, protocol: ?string, referer: ?string, agent: ?string, duration_ms: ?int, request_id: ?string}|null
     */
    public function parse(string $line): ?array
    {
        if (preg_match($this->regex, $line, $m) !== 1) {
            return null;
        }

        $field = function (string $name) use ($m): ?string {
            foreach ($this->groups as $group => $meaning) {
                if ($meaning === $name && isset($m[$group]) && $m[$group] !== '' && $m[$group] !== '-') {
                    return self::unescape($m[$group]);
                }
            }

            return null;
        };

        $time = $this->time($field('time'));

        if ($time === null) {
            return null;
        }

        [$method, $path, $protocol] = [$field('method'), $field('path'), $field('protocol')];

        if (($request = $field('request')) !== null) {
            $parts = explode(' ', $request, 3);
            [$method, $path, $protocol] = count($parts) === 3 ? $parts : [null, $request, null];
        } elseif ($path !== null && ($query = $field('query')) !== null) {
            $path .= $query;
        }

        $vhost = $field('vhost');
        $port = $field('port');
        $duration = match (true) {
            ($us = $field('duration_us')) !== null && ctype_digit($us) => intdiv((int) $us, 1000),
            ($ms = $field('duration_ms')) !== null && ctype_digit($ms) => (int) $ms,
            ($s = $field('duration_s')) !== null && ctype_digit($s) => (int) $s * 1000,
            default => null,
        };

        return [
            'time' => $time,
            'status' => (int) ($field('status') ?? 0),
            'bytes' => (int) ($field('bytes') ?? 0),
            'client' => $field('client'),
            'vhost' => $vhost !== null && $port !== null && !str_contains($vhost, ':') ? "$vhost:$port" : $vhost,
            'method' => $method,
            'path' => $path,
            'protocol' => $protocol,
            'referer' => $field('referer'),
            'agent' => $field('agent'),
            'duration_ms' => $duration,
            'request_id' => $field('request_id'),
        ];
    }

    private function compile(string $format): string
    {
        $regex = '';
        $length = strlen($format);
        $i = 0;
        $n = 0;

        while ($i < $length) {
            // %[conditions][<>]{param}letter, %^ti / %^to, or %% for a literal %.
            if ($format[$i] !== '%' || preg_match('/\G%(?:!?\d{3}(?:,\d{3})*)?([<>]?)(?:\{([^}]*)\})?(\^t[io]|[a-zA-Z%])/', $format, $m, 0, $i) !== 1) {
                $regex .= preg_quote($format[$i], '~');
                $i++;

                continue;
            }

            $i += strlen($m[0]);

            if ($m[3] === '%') {
                $regex .= '%';

                continue;
            }

            // Inside quotes a value may hold escaped quotes (\"); elsewhere it's one word.
            $quoted = $i < $length && $format[$i] === '"' && $regex !== '' && str_ends_with($regex, '"');
            $meaning = $this->meaning($m[3], strtolower($m[2]));
            $pattern = match (true) {
                $meaning === 'time' && $m[2] === '' => '\[([^\]]+)\]',
                $quoted => '((?:[^"\\\\]|\\\\.)*)',
                in_array($meaning, ['status'], true) => '(\d{3}|-)',
                in_array($meaning, ['bytes', 'duration_us', 'duration_ms', 'duration_s'], true) => '(\d+|-)',
                $meaning === 'time' => '(.+?)',
                $meaning === 'path' => '([^\s?]*)',
                default => '(\S*)',
            };

            if ($meaning === 'time' && $m[2] !== '') {
                $this->timeFormat = $m[2];
            }

            $n++;
            $regex .= $pattern;
            // The first of each kind counts (e.g. %O before %b).
            $this->groups[(string) $n] = in_array($meaning, $this->groups, true) ? 'other' : $meaning;
        }

        return $regex;
    }

    private function meaning(string $letter, string $param): string
    {
        return match ($letter) {
            'h', 'a' => 'client',
            't' => 'time',
            'r' => 'request',
            'm' => 'method',
            'U' => 'path',
            'q' => 'query',
            'H' => 'protocol',
            's' => 'status',
            'O', 'b', 'B' => 'bytes',
            'v', 'V' => 'vhost',
            'p' => $param === '' || $param === 'canonical' || $param === 'local' ? 'port' : 'other',
            'D' => 'duration_us',
            'T' => match ($param) {
                'ms' => 'duration_ms', 'us' => 'duration_us', default => 'duration_s'
            },
            'i' => match ($param) {
                'referer' => 'referer', 'user-agent' => 'agent', default => 'other'
            },
            // mod_unique_id's ID, and the request's log ID (the same ID when mod_unique_id sets it).
            'e' => $param === 'unique_id' ? 'request_id' : 'other',
            'L' => 'request_id',
            default => 'other',
        };
    }

    private function time(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($this->timeFormat === null) {
            $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $value);

            return $time === false ? null : $time->getTimestamp();
        }

        // %{sec}t, %{msec}t, %{usec}t, or strftime (the common conversions; begin:/end: prefixes as the same).
        $format = (string) preg_replace('/^(?:begin|end):/', '', $this->timeFormat);

        if (in_array($format, ['sec', 'msec', 'usec'], true)) {
            return ctype_digit($value) ? intdiv((int) $value, ['sec' => 1, 'msec' => 1000, 'usec' => 1000000][$format]) : null;
        }

        $php = strtr(preg_replace('/%\{?(msec|usec)_frac\}?/', '', $format) ?? $format, [
            '%d' => 'd', '%e' => 'j', '%b' => 'M', '%h' => 'M', '%B' => 'F', '%m' => 'm', '%Y' => 'Y', '%y' => 'y',
            '%H' => 'H', '%M' => 'i', '%S' => 's', '%T' => 'H:i:s', '%F' => 'Y-m-d', '%z' => 'O', '%Z' => 'T',
            '%a' => 'D', '%A' => 'l', '%j' => 'z', '%s' => 'U', '%%' => '%',
        ]);
        $time = \DateTimeImmutable::createFromFormat('!' . $php, $value, new \DateTimeZone('UTC'));

        return $time === false ? null : $time->getTimestamp();
    }

    /**
     * Apache escapes quotes, backslashes and control characters (\" \\ \xhh) in what it logs.
     */
    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(x[0-9a-fA-F]{2}|.)/', fn ($m) => match (true) {
            $m[1][0] === 'x' && strlen($m[1]) === 3 => chr((int) hexdec(substr($m[1], 1))),
            $m[1] === 'n' => "\n",
            $m[1] === 't' => "\t",
            default => $m[1],
        }, $value);
    }
}
