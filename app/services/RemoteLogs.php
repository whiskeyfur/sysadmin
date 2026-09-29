<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use App\Models\Model;
use App\Models\Server;
use Carbon\Carbon;
use DateTimeZone;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Reading log files on a server over an open SSH connection, shared by the
 * MariaDB and Apache log imports: what's new in a file since last time,
 * the server's timezone, and storing entries without duplicates.
 */
class RemoteLogs
{
    public function __construct(
        private readonly SshService $ssh,
        private readonly int $maxBytes = MariadbLogService::MAX_BYTES,
    ) {
    }

    /**
     * What's new in a log file since the last read: from the byte the last
     * read stopped at, or (new, rotated or truncated file) the last
     * $maxBytes. Stops at the last complete line, so a line being written is
     * read whole next time.
     *
     * @param array<string, array{inode: string, offset: int}> $state
     *
     * @throws ServerConnectionException
     */
    public function readNew(SSH2 $connection, string $path, array &$state): string
    {
        $quoted = escapeshellarg($path);
        // "<inode> <path>" then the size in bytes.
        $info = preg_split('/\R/', trim($this->ssh->exec($connection, "{ ls -di -- $quoted && wc -c < $quoted; } 2>&1"))) ?: [];
        $inode = (string) strtok((string) ($info[0] ?? ''), ' ');
        $size = (int) trim((string) ($info[1] ?? '0'));
        $previous = $state[$path] ?? null;
        $start = $previous !== null && $previous['inode'] === $inode && $previous['offset'] <= $size ? $previous['offset'] : 0;
        $start = max($start, $size - $this->maxBytes);

        $text = $start >= $size ? '' : $this->ssh->exec($connection, sprintf('tail -c +%d -- %s 2>&1', $start + 1, $quoted));
        $complete = strrpos($text, "\n");
        $text = $complete === false ? '' : substr($text, 0, $complete + 1);
        $state[$path] = ['inode' => $inode, 'offset' => $start + strlen($text)];

        return $text;
    }

    public function zone(SSH2 $connection): DateTimeZone
    {
        $offset = trim($this->run($connection, 'date +%z'));

        try {
            return new DateTimeZone(preg_match('/^[+-]\d{4}$/', $offset) === 1 ? $offset : '+0000');
        } catch (Throwable) {
            return new DateTimeZone('+0000');
        }
    }

    /**
     * Run a command whose failure isn't fatal: output, or '' on failure.
     */
    public function run(SSH2 $connection, string $command): string
    {
        try {
            return $this->ssh->exec($connection, 'sh -c ' . escapeshellarg($command));
        } catch (ServerConnectionException) {
            return '';
        }
    }

    /**
     * Store log entries not stored before, in batches.
     *
     * @param class-string<Model> $model a table with server_id, source, level, logged_at, message and a unique hash
     * @param list<array{time: Carbon, level: string, message: string}> $entries
     * @param array{second: int|null, counts: array<string, int>} $repeats identical messages in the current second, across batches
     * @return int how many were new
     */
    public function store(string $model, Server $server, string $source, array $entries, array &$repeats): int
    {
        $rows = [];

        foreach ($entries as $entry) {
            $time = $entry['time']->copy()->utc()->startOfSecond();
            $second = $time->getTimestamp();
            $key = implode("\0", [$server->id, $source, $second, $entry['level'], $entry['message']]);

            // The same message twice in one second is two events: number the repeats.
            if ($repeats['second'] !== $second) {
                $repeats = ['second' => $second, 'counts' => []];
            }

            $repeats['counts'][$key] = ($repeats['counts'][$key] ?? 0) + 1;
            $hash = hash('sha256', $key . "\0" . $repeats['counts'][$key]);
            $rows[$hash] = [
                'server_id' => $server->id,
                'source' => $source,
                'level' => $entry['level'],
                'logged_at' => $time->format('Y-m-d H:i:s'),
                'message' => $entry['message'],
                'hash' => $hash,
            ];
        }

        $new = 0;

        // One insert per batch (row by row, a big log is too slow for a web request).
        foreach (array_chunk($rows, 250, true) as $chunk) {
            $existing = $model::query()->whereIn('hash', array_keys($chunk))->pluck('hash')->flip();
            $fresh = array_values(array_diff_key($chunk, $existing->all()));

            if ($fresh !== []) {
                $model::query()->insert($fresh);
                $new += count($fresh);
            }
        }

        return $new;
    }
}
