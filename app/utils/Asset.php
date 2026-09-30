<?php

namespace App\Utils;

/**
 * URLs of files in public/, with the file's modification time added (?v=...), so browsers fetch a
 * script again as soon as it changes instead of running a cached copy (Apache sends no Cache-Control
 * for them, which leaves browsers guessing how long a copy stays fresh).
 */
class Asset
{
    public static function url(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $time = is_file($file) ? filemtime($file) : false;

        return $time === false ? $path : $path . '?v=' . $time;
    }
}
