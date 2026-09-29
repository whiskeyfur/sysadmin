<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use phpseclib3\Net\SSH2;

/**
 * Reads Apache's configuration starting from its main file, for servers
 * where Apache's control program (and so `-t -D DUMP_INCLUDES`) isn't in
 * the SSH user's PATH: follows Include / IncludeOptional as Apache does
 * (files, directories, wildcards; relative to ServerRoot) and returns the
 * whole configuration as one text, includes in place, so directives inside
 * a <VirtualHost> keep their block.
 *
 * ServerRoot comes from the file; when it doesn't say, the main file's
 * directory is used (Debian's layout) and a note says so. ${VARIABLES}
 * come from Define lines and, when there is one, the `envvars` file
 * Debian's apache2ctl reads from ServerRoot.
 */
class ApacheConfigFiles
{
    /**
     * Stop following includes after this many files (a loop, or a huge tree).
     */
    public const MAX_FILES = 500;

    private int $read = 0;

    public function __construct(
        private readonly SshService $ssh,
        private readonly ApacheConfigParser $parser = new ApacheConfigParser(),
    ) {
    }

    /**
     * @return array{text: string, files: int, server_root: string, variables: array<string, string>, notes: list<string>}
     *
     * @throws \DomainException when the main file can't be read
     */
    public function read(SSH2 $connection, string $path): array
    {
        try {
            $main = $this->ssh->exec($connection, 'cat -- ' . escapeshellarg($path) . ' 2>&1');
        } catch (ServerConnectionException $e) {
            throw new \DomainException("Couldn't read Apache's configuration file $path: {$e->getMessage()}");
        }

        $notes = [];
        $root = $this->serverRoot($main);

        if ($root === null) {
            $root = dirname($path);
            $notes[] = "The configuration doesn't set ServerRoot; relative paths are taken from $root.";
        }

        $variables = $this->envvars($connection, $root);
        $this->read = 1;
        $text = $this->expand($connection, $main, $root, $variables, [$path => true], $notes);

        return ['text' => $text, 'files' => $this->read, 'server_root' => $root, 'variables' => $variables, 'notes' => $notes];
    }

    /**
     * ServerRoot as set in the main file's top level.
     */
    public function serverRoot(string $text): ?string
    {
        foreach ($this->parser->directives($text) as $directive) {
            if ($directive['name'] === 'serverroot' && isset($directive['args'][0]) && $directive['vhost'] === null) {
                return rtrim($directive['args'][0], '/') ?: '/';
            }
        }

        return null;
    }

    /**
     * `export NAME=value` lines of a shell file like Debian's envvars;
     * $OTHER references resolve from earlier lines, unknown ones (like
     * $SUFFIX, set only for extra instances) are empty.
     *
     * @return array<string, string>
     */
    public function exports(string $text): array
    {
        $variables = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*export\s+(\w+)=(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $value = trim((string) preg_replace('/\s+#.*$/', '', $m[2]));
            $value = trim($value, '"\'');
            $variables[$m[1]] = (string) preg_replace_callback('/\$\{?(\w+)\}?/', fn ($v) => $variables[$v[1]] ?? '', $value);
        }

        return $variables;
    }

    /**
     * Which files an Include argument names, in the order Apache reads them:
     * a file, every file under a directory, or wildcard matches (in any path
     * component; a matching directory brings every file under it).
     *
     * @param list<string> $found paths `find` printed under the pattern's fixed base
     * @return list<string>
     */
    public function matches(string $pattern, array $found): array
    {
        [$base, $rest] = $this->splitPattern($pattern);

        if ($rest === []) {
            $files = $found;
        } else {
            $files = array_filter($found, function (string $file) use ($base, $rest) {
                $relative = explode('/', ltrim(substr($file, strlen($base)), '/'));

                if (count($relative) < count($rest)) {
                    return false;
                }

                foreach ($rest as $i => $part) {
                    if (!fnmatch($part, $relative[$i], FNM_PERIOD)) {
                        return false;
                    }
                }

                return true;
            });
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, true> $seen files already on the include path (loops)
     * @param list<string> $notes
     */
    private function expand(SSH2 $connection, string $text, string $root, array $variables, array $seen, array &$notes): string
    {
        $out = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(Include|IncludeOptional)\s+(.+)$/i', $line, $m) !== 1) {
                $out[] = $line;

                continue;
            }

            $optional = strtolower($m[1]) === 'includeoptional';
            $argument = (string) ($this->parser->arguments($m[2])[0] ?? '');
            $argument = (string) preg_replace_callback('/\$\{(\w+)\}/', fn ($v) => $variables[$v[1]] ?? $v[0], $argument);

            if ($argument === '' || str_contains($argument, '${')) {
                $notes[] = "Include $argument: couldn't resolve the path.";

                continue;
            }

            $pattern = str_starts_with($argument, '/') ? $argument : "$root/$argument";
            $files = $this->find($connection, $pattern);

            if ($files === [] && !$optional && !$this->hasWildcard($pattern)) {
                $notes[] = "Include $pattern: not found or not readable.";
            }

            foreach ($files as $file) {
                if (isset($seen[$file])) {
                    continue;
                }

                if ($this->read >= self::MAX_FILES) {
                    $notes[] = 'Stopped following includes after ' . self::MAX_FILES . ' files.';

                    break 2;
                }

                try {
                    $included = $this->ssh->exec($connection, 'cat -- ' . escapeshellarg($file) . ' 2>&1');
                } catch (ServerConnectionException) {
                    $notes[] = "Couldn't read $file.";

                    continue;
                }

                $this->read++;
                $out[] = $this->expand($connection, $included, $root, $variables, $seen + [$file => true], $notes);
            }
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private function find(SSH2 $connection, string $pattern): array
    {
        [$base, $rest] = $this->splitPattern($pattern);
        $depth = count($rest) > 0 ? ' -mindepth ' . count($rest) : '';
        // -L: sites-enabled and the like are symlinks. Every path is absolute, so none reads as an option.
        $command = 'find -L ' . escapeshellarg($base) . "$depth -type f 2>/dev/null | head -n " . (self::MAX_FILES * 4);

        try {
            $output = $this->ssh->exec($connection, 'sh -c ' . escapeshellarg($command));
        } catch (ServerConnectionException) {
            return [];
        }

        $found = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), fn ($l) => str_starts_with($l, '/')));

        return $this->matches($pattern, $found);
    }

    /**
     * The part of a path before its first wildcard component, and the
     * components from there on.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function splitPattern(string $pattern): array
    {
        $parts = explode('/', rtrim($pattern, '/'));

        foreach ($parts as $i => $part) {
            if ($this->hasWildcard($part)) {
                return [implode('/', array_slice($parts, 0, $i)) ?: '/', array_slice($parts, $i)];
            }
        }

        return [rtrim($pattern, '/') ?: '/', []];
    }

    private function hasWildcard(string $path): bool
    {
        return strpbrk($path, '*?[') !== false;
    }

    /**
     * @return array<string, string>
     */
    private function envvars(SSH2 $connection, string $root): array
    {
        try {
            return $this->exports($this->ssh->exec($connection, 'cat -- ' . escapeshellarg("$root/envvars") . ' 2>&1'));
        } catch (ServerConnectionException) {
            return [];
        }
    }
}
