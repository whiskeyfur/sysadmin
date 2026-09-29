<?php

namespace App\Contracts;

use App\DTOs\CheckResult;

/**
 * A health check that reads the output of shell commands on the server.
 * All SSH checks run together in one SSH session per run (one login, which
 * matters on servers with fail2ban); each contributes its commands and
 * judges its own section of the output.
 */
interface SshHealthCheck
{
    public function key(): string;

    public function label(): string;

    /**
     * Read-only POSIX sh commands; errors should go to /dev/null.
     */
    public function command(): string;

    /**
     * Judge this check's section of the output. Must not throw for expected
     * problems (e.g. not Linux); return "unknown" with an explanation.
     */
    public function evaluate(string $output): CheckResult;
}
