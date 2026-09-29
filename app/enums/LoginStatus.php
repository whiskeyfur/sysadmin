<?php

namespace App\Enums;

/**
 * Outcome of a sign-in, first-login setup or password change.
 */
enum LoginStatus: string
{
    case Success = 'success';
    // Wrong username, password or authenticator code. One status for all
    // three so the response doesn't reveal which part was wrong.
    case InvalidCredentials = 'invalid_credentials';
    // The code for a new authenticator didn't match its secret.
    case InvalidCode = 'invalid_code';
    // New account or admin reset: set a password and enrol an authenticator.
    case NeedsSetup = 'needs_setup';
    // The new password breaks the policy; LoginResult::$message says why.
    case PasswordRejected = 'password_rejected';
    // Rate limited; LoginResult::$retryAfter says for how long.
    case TooManyAttempts = 'too_many_attempts';
}
