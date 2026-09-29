<?php

namespace App\Contracts;

use App\DTOs\CheckResult;
use PDO;

/**
 * One MariaDB/MySQL health check. Implementations query in run() and keep
 * the judgement in a pure evaluate() method so it can be unit-tested.
 */
interface HealthCheck
{
    public function key(): string;

    public function label(): string;

    /**
     * Must not throw for expected problems (missing privilege, feature not
     * in use); return an "unknown" or "ok" result with an explanation.
     */
    public function run(PDO $pdo): CheckResult;
}
