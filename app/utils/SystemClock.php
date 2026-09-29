<?php

namespace App\Utils;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The real clock, for services that need the time injected (tests swap it).
 */
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
