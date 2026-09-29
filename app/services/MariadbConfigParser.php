<?php

namespace App\Services;

/**
 * Parses MariaDB/MySQL option files (my.cnf format): [section] headers,
 * "name = value" or bare "name" options (bare means on), comments (# ;),
 * and !include / !includedir lines. Option names are normalised as MariaDB
 * does: dashes and underscores are the same ("log-error" = "log_error").
 */
class MariadbConfigParser
{
    /**
     * Sections the server reads. "mysqld-10.11", "mariadb-10.11" and
     * "mariadbd-..." (version-specific) count as their base section.
     */
    public const SERVER_SECTIONS = ['mysqld', 'server', 'mariadb', 'mariadbd'];

    /**
     * @return array{
     *     options: list<array{section: string, name: string, value: string|null}>,
     *     includes: list<array{type: 'file'|'dir', path: string}>
     * } options and includes in file order; value null for a bare option
     */
    public function parse(string $text): array
    {
        $options = [];
        $includes = [];
        $section = '';

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^!include(dir)?\s+(.+)$/i', $line, $m) === 1) {
                $includes[] = ['type' => $m[1] === '' ? 'file' : 'dir', 'path' => trim($m[2])];

                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $line, $m) === 1) {
                $section = strtolower(trim($m[1]));

                continue;
            }

            // "name = value  # comment", "name=value", or a bare "name".
            if (preg_match('/^([A-Za-z0-9_.-]+)\s*(?:=\s*(.*))?$/', $line, $m) !== 1) {
                continue;
            }

            $value = isset($m[2]) ? $this->value($m[2]) : null;
            $options[] = ['section' => $section, 'name' => $this->name($m[1]), 'value' => $value];
        }

        return ['options' => $options, 'includes' => $includes];
    }

    /**
     * Whether a section applies to the server.
     */
    public function isServerSection(string $section): bool
    {
        foreach (self::SERVER_SECTIONS as $base) {
            if ($section === $base || str_starts_with($section, $base . '-')) {
                return true;
            }
        }

        return false;
    }

    public function name(string $name): string
    {
        // Prefixes like "loose-" only change how unknown options are treated.
        return str_replace('-', '_', (string) preg_replace('/^loose[-_]/i', '', strtolower(trim($name))));
    }

    private function value(string $raw): string
    {
        $raw = trim($raw);

        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $quote = $raw[0];
            $end = strpos($raw, $quote, 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        // An unquoted value ends at a comment.
        return trim((string) preg_replace('/\s+[#;].*$/', '', $raw));
    }
}
