<?php

namespace App\Exceptions;

use App\DTOs\HostKey;

/**
 * The server presented a different host key from the trusted one. Either the
 * server was reinstalled or someone is intercepting the connection; nothing
 * is sent until an admin investigates.
 */
class HostKeyMismatchException extends ServerConnectionException
{
    public function __construct(public readonly HostKey $trusted, public readonly HostKey $presented)
    {
        parent::__construct(
            'The server\'s host key has changed (trusted ' . $trusted->fingerprint() . ', presented ' . $presented->fingerprint()
            . '). This can mean the connection is being intercepted. The connection was stopped before logging in.',
        );
    }
}
