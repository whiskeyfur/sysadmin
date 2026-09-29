<?php

namespace App\Exceptions;

/**
 * The user's stored master key no longer unlocks the vault; they must upload the current key file.
 */
class StaleMasterKeyException extends KeyException
{
}
