<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use phpseclib3\Net\SSH2;

/**
 * Commands run inside a Docker or Podman container on the server, over the
 * same SSH connection (`docker exec <name> sh -c '...'`), for Apache running
 * in a container. Everything that reads the server through SshService::exec()
 * (RemoteLogs, ApacheConfigFiles) works unchanged with it.
 *
 * Containers usually log to their stdout/stderr rather than files; those are
 * read on the host with `docker logs` (readStream()).
 */
class ContainerShell extends SshService
{
    public const RUNTIMES = ['docker', 'podman'];

    /**
     * How a remembered container is written: "docker:web".
     */
    public const PATTERN = '/^(docker|podman):([A-Za-z0-9][A-Za-z0-9_.-]{0,127})$/';

    /**
     * Log lines read from a stream the first time (later reads continue after the last one read).
     */
    public const FIRST_LINES = 20000;

    public function __construct(
        private readonly SshService $host,
        public readonly string $runtime,
        public readonly string $container,
    ) {
        if (!in_array($runtime, self::RUNTIMES, true) || preg_match(self::PATTERN, "$runtime:$container") !== 1) {
            throw new \DomainException("Not a container: $runtime:$container");
        }
    }

    /**
     * From a remembered "docker:web"; null if it isn't one.
     */
    public static function fromReference(SshService $host, ?string $reference): ?self
    {
        return $reference !== null && preg_match(self::PATTERN, $reference, $m) === 1 ? new self($host, $m[1], $m[2]) : null;
    }

    public function reference(): string
    {
        return "{$this->runtime}:{$this->container}";
    }

    /**
     * @throws ServerConnectionException
     */
    public function exec(SSH2 $ssh, string $command): string
    {
        return $this->host->exec($ssh, $this->runtime . ' exec ' . escapeshellarg($this->container) . ' sh -c ' . escapeshellarg($command));
    }

    /**
     * Whether a log path is really the container's output: /dev/stdout,
     * /proc/self/fd/2 and the like (directly or through a symlink, as
     * resolved inside the container). Returns 'stdout', 'stderr' or null.
     */
    public static function streamOf(string $resolved): ?string
    {
        return match (true) {
            preg_match('#^/(?:dev/stdout|dev/fd/1|proc/(?:self|1)/fd/1)$#', $resolved) === 1 => 'stdout',
            preg_match('#^/(?:dev/stderr|dev/fd/2|proc/(?:self|1)/fd/2)$#', $resolved) === 1 => 'stderr',
            default => null,
        };
    }

    /**
     * What's new on the container's stdout or stderr since the last read
     * (`docker logs --timestamps --since`), without the timestamps.
     *
     * @param array<string, mixed> $state per key, the last line's timestamp
     *
     * @throws ServerConnectionException
     */
    public function readStream(SSH2 $ssh, string $stream, string $key, array &$state): string
    {
        $last = is_array($state[$key] ?? null) ? ($state[$key]['since'] ?? null) : null;
        $last = is_string($last) && $this->instant($last) !== null ? $last : null;
        $window = $last === null ? '--tail ' . self::FIRST_LINES : '--since ' . escapeshellarg($last);
        // stdout: the log's stdout only; stderr: its stderr only, onto ours.
        $redirect = $stream === 'stdout' ? '2>/dev/null' : '2>&1 >/dev/null';
        $output = $this->host->exec($ssh, "{$this->runtime} logs --timestamps $window " . escapeshellarg($this->container) . " $redirect");

        $lines = [];
        $newest = $last;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^(\S+) ?(.*)$/', $line, $m) !== 1 || ($at = $this->instant($m[1])) === null) {
                continue;
            }

            // --since includes the line it starts at.
            if ($last !== null && $at <= $this->instant($last)) {
                continue;
            }

            $lines[] = $m[2];
            $newest = $m[1];
        }

        $state[$key] = ['since' => $newest];

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * A docker/podman log timestamp (RFC 3339 with nanoseconds) as a
     * sortable "seconds.nanoseconds" string; null if it isn't one.
     */
    private function instant(string $timestamp): ?string
    {
        if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.(\d{1,9}))?(Z|[+-]\d\d:?\d\d)$/', $timestamp, $m) !== 1) {
            return null;
        }

        $seconds = strtotime($m[1] . $m[3]);

        return $seconds === false ? null : sprintf('%012d.%s', $seconds, str_pad($m[2], 9, '0'));
    }
}
