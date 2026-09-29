<?php

namespace App\Middleware;

/**
 * For the change-password page itself, which must stay reachable when the
 * password has expired.
 */
class AuthenticateAllowingExpiredPassword extends Authenticate
{
    protected bool $allowExpiredPassword = true;
}
