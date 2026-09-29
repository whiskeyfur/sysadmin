<?php

namespace App\Services;

/**
 * Reads Apache's configuration as Apache reports it: the output of
 * `apache2ctl -S` / `-t -D DUMP_INCLUDES` and the configuration files
 * themselves (directives, <VirtualHost> and <Location> blocks, quoted
 * arguments, lines continued with a backslash).
 */
class ApacheConfigParser
{
    /**
     * Directives in file order.
     *
     * @return list<array{name: string, args: list<string>, vhost: ?string, location: ?string}>
     *         name lowercased; vhost/location: the enclosing block's argument, if any
     */
    public function directives(string $text): array
    {
        $directives = [];
        $stack = [];
        $pending = '';

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            // A trailing backslash continues the directive on the next line.
            if (str_ends_with($line, '\\')) {
                $pending .= substr($line, 0, -1) . ' ';

                continue;
            }

            $line = trim($pending . $line);
            $pending = '';

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('#^</(\w+)\s*>$#', $line, $m) === 1) {
                array_pop($stack);

                continue;
            }

            if (preg_match('#^<(\w+)\s*([^>]*)>$#', $line, $m) === 1) {
                $stack[] = [strtolower($m[1]), trim($m[2], " \t\"")];

                continue;
            }

            $parts = $this->arguments($line);
            $name = strtolower((string) array_shift($parts));
            $vhost = null;
            $location = null;

            foreach ($stack as [$block, $argument]) {
                if ($block === 'virtualhost') {
                    $vhost = $argument;
                } elseif (in_array($block, ['location', 'locationmatch'], true)) {
                    $location = $argument;
                }
            }

            $directives[] = ['name' => $name, 'args' => $parts, 'vhost' => $vhost, 'location' => $location];
        }

        return $directives;
    }

    /**
     * Split a directive into words, honouring "double" and 'single' quotes.
     *
     * @return list<string>
     */
    public function arguments(string $line): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $line, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => isset($m[3]) ? $m[3] : (isset($m[2]) && $m[2] !== '' ? $m[2] : stripcslashes($m[1])), $matches);
    }

    /**
     * The files `-t -D DUMP_INCLUDES` lists, in the order Apache reads them.
     *
     * @return list<string>
     */
    public function includedFiles(string $output): array
    {
        preg_match_all('#^\s*\((?:\*|\d+)\)\s+(/\S.*)$#m', $output, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /**
     * ServerRoot, the main error log and the listening addresses from `-S`.
     *
     * @return array{server_root: ?string, main_error_log: ?string}
     */
    public function runtime(string $output): array
    {
        return [
            'server_root' => preg_match('/^ServerRoot: "([^"]+)"/m', $output, $m) === 1 ? $m[1] : null,
            'main_error_log' => preg_match('/^Main ErrorLog: "([^"]+)"/m', $output, $m) === 1 ? $m[1] : null,
        ];
    }
}
