<?php

namespace App\Utils;

/**
 * The code the install wizard asks for before it sets up a database. It's
 * made on first use and only readable on the server itself
 * (storage/framework/install-code, or `php leaf app:install-code`), so
 * someone who merely reaches the site first can't point it at a database
 * of their own and claim it.
 */
class InstallCode
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/framework/install-code';
    }

    /**
     * The code, created if there isn't one yet.
     */
    public static function current(): string
    {
        $path = self::path();
        $code = is_readable($path) ? trim((string) file_get_contents($path)) : '';

        if (preg_match('/^[a-z0-9]{5}(-[a-z0-9]{5}){3}$/', $code) === 1) {
            return $code;
        }

        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = implode('-', array_map(function () use ($alphabet) {
            $part = '';

            for ($i = 0; $i < 5; $i++) {
                $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $part;
        }, range(1, 4)));

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o2775, true);
        }

        if (file_put_contents($path, "$code\n", LOCK_EX) === false) {
            throw new \RuntimeException("Can't write $path.");
        }

        @chmod($path, 0o640);

        return $code;
    }

    public static function matches(string $typed): bool
    {
        return hash_equals(self::current(), strtolower(trim($typed)));
    }

    /**
     * Once set up, the code has done its job.
     */
    public static function forget(): void
    {
        @unlink(self::path());
    }
}
