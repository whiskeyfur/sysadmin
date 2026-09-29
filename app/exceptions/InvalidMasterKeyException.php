<?php

namespace App\Exceptions;

/**
 * A supplied master key does not unlock the vault.
 */
class InvalidMasterKeyException extends KeyException
{
}
