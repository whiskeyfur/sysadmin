<?php

namespace App\Exceptions;

/**
 * The vault is not in the state the operation requires (for example, already initialized).
 */
class VaultStateException extends KeyException
{
}
