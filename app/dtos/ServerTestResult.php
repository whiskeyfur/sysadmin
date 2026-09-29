<?php

namespace App\DTOs;

/**
 * Outcome of testing a server's SSH and MySQL connections.
 */
class ServerTestResult
{
    public function __construct(
        // Null when SSH is turned off for the server.
        public readonly ?bool $sshOk,
        public readonly ?string $sshMessage,
        public readonly ?bool $mysqlOk,
        public readonly ?string $mysqlMessage,
        // Set when the host key isn't trusted yet: the key to show the admin.
        public readonly ?HostKey $untrustedHostKey = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->sshOk !== false && $this->mysqlOk !== false;
    }

    public function summary(): string
    {
        $parts = $this->sshMessage === null ? [] : ['SSH: ' . $this->sshMessage];

        if ($this->mysqlMessage !== null) {
            $parts[] = 'MySQL: ' . $this->mysqlMessage;
        }

        return implode(' ', $parts);
    }
}
