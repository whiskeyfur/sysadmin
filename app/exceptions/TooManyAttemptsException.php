<?php

namespace App\Exceptions;

/**
 * A password check was refused by the rate limiter.
 */
class TooManyAttemptsException extends KeyException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Too many attempts. Try again in $retryAfter seconds.");
    }
}
