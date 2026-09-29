<?php

namespace App\Exceptions;

/**
 * An encrypted secret could not be decrypted: wrong app key, wrong context or tampered data.
 */
class DecryptionException extends SecurityException
{
}
