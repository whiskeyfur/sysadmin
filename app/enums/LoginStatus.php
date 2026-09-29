<?php

namespace App\Enums;

/**
 * Outcome of a login, setup, key replacement or registration attempt.
 */
enum LoginStatus: string
{
    case Success = 'success';
    // Wrong username, password or authenticator code. One status for all
    // three so the response doesn't reveal which part was wrong.
    case InvalidCredentials = 'invalid_credentials';
    // The code for a new authenticator didn't match its secret.
    case InvalidCode = 'invalid_code';
    // First login or after an admin reset: set a password and enrol an authenticator.
    case NeedsSetup = 'needs_setup';
    // The master key was rotated; the user must upload the current key file.
    case StaleKey = 'stale_key';
    case InvalidKeyFile = 'invalid_key_file';
    case UsernameTaken = 'username_taken';
    // Registered but not yet approved by an admin.
    case Pending = 'pending';
}
