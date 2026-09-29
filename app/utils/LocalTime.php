<?php

namespace App\Utils;

use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Times are stored in UTC; show them in APP_TIMEZONE (from .env).
 */
class LocalTime
{
    public static function format(?DateTimeInterface $time, string $format = 'Y-m-d H:i'): string
    {
        return $time === null ? '' : Carbon::instance($time)->setTimezone(self::zone())->format($format);
    }

    public static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) _env('APP_TIMEZONE', 'UTC'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
