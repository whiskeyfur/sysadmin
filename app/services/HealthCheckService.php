<?php

namespace App\Services;

use App\Contracts\HealthCheck;
use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Models\User;
use App\Services\Checks\BufferPoolCheck;
use App\Services\Checks\ConnectionsCheck;
use App\Services\Checks\CrashedTablesCheck;
use App\Services\Checks\ReplicationCheck;
use App\Services\Checks\ServerStatusCheck;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Runs the MariaDB/MySQL health checks for a server, stores the results
 * (kept RETENTION_DAYS) and records the worst status on the server.
 */
class HealthCheckService
{
    public const RETENTION_DAYS = 30;

    /**
     * @var list<HealthCheck>
     */
    private readonly array $checks;

    /**
     * @param list<HealthCheck>|null $checks
     */
    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        ?array $checks = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->checks = $checks ?? [
            new ServerStatusCheck(),
            new ConnectionsCheck(),
            new CrashedTablesCheck(),
            new ReplicationCheck(),
            new BufferPoolCheck(),
        ];
    }

    /**
     * @return list<CheckResult>
     *
     * @throws DomainException if the server has no MySQL configured.
     */
    public function run(User $user, Server $server): array
    {
        if (!$user->isAdmin()) {
            throw new AuthorizationException('Only admins can run health checks.');
        }

        if (!$server->mysql_enabled) {
            throw new DomainException("MySQL isn't configured for {$server->name}.");
        }

        $results = $this->runChecks($server);
        $this->store($server, $results);

        return $results;
    }

    /**
     * Display name for a stored check key.
     */
    public function label(string $key): string
    {
        foreach ($this->checks as $check) {
            if ($check->key() === $key) {
                return $check->label();
            }
        }

        return $key === 'connection' ? 'Connection' : ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * The results of the server's most recent run.
     *
     * @return list<StoredCheck>
     */
    public function latest(Server $server): array
    {
        if ($server->last_checked_at === null) {
            return [];
        }

        return StoredCheck::query()
            ->where('server_id', $server->id)
            ->where('checked_at', $server->last_checked_at)
            ->get()
            ->sortBy('id')
            ->values()
            ->all();
    }

    /**
     * Recent statuses per check, oldest first, for a small history strip.
     *
     * @return array<string, list<StoredCheck>>
     */
    public function history(Server $server, int $runs = 24): array
    {
        $times = StoredCheck::query()
            ->where('server_id', $server->id)
            ->distinct()
            ->orderByDesc('checked_at')
            ->limit($runs)
            ->pluck('checked_at');

        if ($times->isEmpty()) {
            return [];
        }

        $history = [];

        $rows = StoredCheck::query()
            ->where('server_id', $server->id)
            ->where('checked_at', '>=', $times->last())
            ->get()
            ->sortBy(['checked_at', 'id']);

        foreach ($rows as $row) {
            $history[$row->check_key][] = $row;
        }

        return $history;
    }

    /**
     * @return list<CheckResult>
     */
    private function runChecks(Server $server): array
    {
        try {
            $pdo = $this->mysql->connect($server);
        } catch (ServerConnectionException $e) {
            return [new CheckResult('connection', 'Connection', HealthStatus::Critical, "Can't connect: {$e->getMessage()}")];
        }

        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[] = $check->run($pdo);
            } catch (Throwable $e) {
                $results[] = new CheckResult($check->key(), $check->label(), HealthStatus::Unknown, "The check failed: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * @param list<CheckResult> $results
     */
    private function store(Server $server, array $results): void
    {
        $now = Carbon::instance($this->clock->now())->startOfSecond();

        (new StoredCheck())->getConnection()->transaction(function () use ($server, $results, $now) {
            foreach ($results as $result) {
                StoredCheck::query()->create([
                    'server_id' => $server->id,
                    'check_key' => $result->key,
                    'status' => $result->status,
                    'summary' => mb_substr($result->summary, 0, 4000),
                    'value' => $result->value,
                    'unit' => $result->unit,
                    'details' => $result->details === [] ? null : $result->details,
                    'checked_at' => $now,
                ]);
            }

            $server->last_checked_at = $now;
            $server->last_health_status = HealthStatus::worst(array_map(fn (CheckResult $r) => $r->status, $results))->value;
            $server->save();

            StoredCheck::query()->where('checked_at', '<', $now->copy()->subDays(self::RETENTION_DAYS))->delete();
        });
    }
}
