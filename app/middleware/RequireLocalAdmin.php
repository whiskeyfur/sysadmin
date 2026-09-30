<?php

namespace App\Middleware;

use App\DTOs\AuthContext;

/**
 * Like RequireAdmin, but only for requests from this machine itself
 * (127.0.0.1 / ::1): managing this machine's Apache runs as root, so it
 * isn't offered over the network. Reach it from elsewhere through an SSH
 * tunnel.
 */
class RequireLocalAdmin extends RequireAdmin
{
    public const LOOPBACK = ['127.0.0.1', '::1'];

    protected function authorize(AuthContext $context): void
    {
        parent::authorize($context);

        if (!self::fromThisMachine()) {
            response()->withFlash('error', 'Managing this machine\'s Apache only works from the machine itself (e.g. through an SSH tunnel).')->redirect('/apache');
        }
    }

    /**
     * REMOTE_ADDR only: forwarded-for headers are the client's to invent.
     */
    public static function fromThisMachine(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', self::LOOPBACK, true);
    }
}
