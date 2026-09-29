<?php

namespace App\DTOs;

/**
 * What SshSetupService did, step by step, for the admin to read.
 */
class SshSetupResult
{
    /**
     * @var list<array{ok: bool, message: string}>
     */
    public array $steps = [];

    public bool $ok = false;

    public function step(bool $ok, string $message): self
    {
        $this->steps[] = ['ok' => $ok, 'message' => $message];

        return $this;
    }

    public function finish(bool $ok): self
    {
        $this->ok = $ok;

        return $this;
    }
}
