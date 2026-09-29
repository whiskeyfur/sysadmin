<?php

namespace App\Enums;

/**
 * Outcome of a sign-in, first-login setup or re-confirmation.
 */
enum LoginStatus: string
{
    case Success = 'success';
    // Wrong username, one-time password or authenticator code. One status
    // for all so the response doesn't reveal which part was wrong.
    case InvalidCredentials = 'invalid_credentials';
    // The code didn't match (a new authenticator's, or when re-confirming).
    case InvalidCode = 'invalid_code';
    // The one-time password was right: enrol an authenticator.
    case NeedsSetup = 'needs_setup';
    // The account was reset or set up elsewhere since setup started.
    case SetupExpired = 'setup_expired';
    // Rate limited; LoginResult::$retryAfter says for how long.
    case TooManyAttempts = 'too_many_attempts';
}
