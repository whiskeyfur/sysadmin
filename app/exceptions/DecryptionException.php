<?php

namespace App\Exceptions;

/**
 * Ciphertext could not be decrypted: wrong key, wrong context or tampered data.
 */
class DecryptionException extends KeyException
{
}
