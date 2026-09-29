<?php

namespace App\Exceptions;

use App\DTOs\HostKey;

/**
 * The server has no trusted host key yet. An admin must check the presented
 * key's fingerprint on the server and trust it.
 */
class HostKeyUnknownException extends ServerConnectionException
{
    public function __construct(public readonly HostKey $presented)
    {
        parent::__construct('The server\'s host key has not been trusted yet (' . $presented->fingerprint() . ').');
    }
}
