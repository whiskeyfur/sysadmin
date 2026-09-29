<?php

namespace App\Services;

use App\Contracts\HealthCheck;
use App\Contracts\SshHealthCheck;
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
use App\Services\Checks\DiskCheck;
use App\Services\Checks\LoadCheck;
use App\Services\Checks\MemoryCheck;
use App\Services\Checks\ReplicationCheck;
use App\Services\Checks\ServerStatusCheck;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Runs a server's health checks, stores the results (kept RETENTION_DAYS)
 * and records the worst status on the server:
 *
 * - SSH checks (disk, load, memory) when SSH is set up. They share one SSH
 *   session per run: one login, which matters on servers with fail2ban.
 *   Servers whose host key isn't trusted yet are skipped, never contacted.
 * - MariaDB/MySQL checks when MySQL is configured.
 */
class HealthCheckService
{
    public const RETENTION_DAYS = 30;

    private const SECTION_MARKER = '@@sys-check:';

    /**
     * @var list<HealthCheck>
     */
    private readonly array $checks;

    /**
     * @var list<SshHealthCheck>
     */
    private readonly array $sshChecks;

    /**
     * @param list<HealthCheck>|null $checks
     * @param list<SshHealthCheck>|null $sshChecks
     */
    public function __construct(
        private readonly MysqlService $mysql = new MysqlService(),
        ?array $checks = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly SshService $ssh = new SshService(),
        ?array $sshChecks = null,
    ) {
        $this->sshChecks = $sshChecks ?? self::defaultSshChecks(new SettingsService());
        $this->checks = $checks ?? [
            new ServerStatusCheck(),
            new ConnectionsCheck(),
            new CrashedTablesCheck(),
            new ReplicationCheck(),
            new BufferPoolCheck(),
        ];
    }

    /**
     * The SSH checks, with the disk levels from the admin settings.
     *
     * @return list<SshHealthCheck>
     */
    public static function defaultSshChecks(SettingsService $settings): array
    {
        return [
            new DiskCheck($settings->diskWarningPercent(), $settings->diskCriticalPercent()),
            new LoadCheck(),
            new MemoryCheck(),
        ];
    }

    /**
     * @return list<CheckResult>
     *
     * @throws DomainException if neither SSH nor MySQL is set up for the server.
     */
    public function run(User $user, Server $server): array
    {
        if (!$user->isAdmin()) {
            throw new AuthorizationException('Only admins can run health checks.');
        }

        if (!$this->canCheck($server)) {
            throw new DomainException("Nothing to check on {$server->name} yet: set up SSH or configure MySQL first.");
        }

        $results = array_merge(
            $server->sshReady() ? $this->runSshChecks($server) : [],
            $server->mysql_enabled ? $this->runChecks($server) : [],
        );
        $this->store($server, $results);

        return $results;
    }

    /**
     * Display name for a stored check key.
     */
    public function label(string $key): string
    {
        foreach ([...$this->sshChecks, ...$this->checks] as $check) {
            if ($check->key() === $key) {
                return $check->label();
            }
        }

        return match ($key) {
            'connection' => 'MySQL connection',
            'ssh' => 'SSH connection',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    public function canCheck(Server $server): bool
    {
        return $server->sshReady() || $server->mysql_enabled;
    }

    /**
     * The one script the SSH checks share: each check's commands after a
     * marker line, so the output can be split back per check.
     */
    public function sshScript(): string
    {
        $parts = array_map(
            fn (SshHealthCheck $check) => "echo '" . self::SECTION_MARKER . $check->key() . "'; " . $check->command(),
            $this->sshChecks,
        );

        return 'sh -c ' . escapeshellarg(implode('; ', $parts) . '; exit 0');
    }

    /**
     * @return array<string, string> output per check key
     */
    public function splitSections(string $output): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, self::SECTION_MARKER)) {
                $current = substr($line, strlen(self::SECTION_MARKER));
                $sections[$current] = '';
            } elseif ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
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
    private function runSshChecks(Server $server): array
    {
        try {
            $sections = $this->splitSections($this->ssh->run($server, $this->sshScript()));
        } catch (ServerConnectionException $e) {
            return [new CheckResult('ssh', 'SSH connection', HealthStatus::Critical, "Can't connect over SSH: {$e->getMessage()}")];
        }

        $results = [];

        foreach ($this->sshChecks as $check) {
            try {
                $results[] = $check->evaluate($sections[$check->key()] ?? '');
            } catch (Throwable $e) {
                $results[] = new CheckResult($check->key(), $check->label(), HealthStatus::Unknown, "The check failed: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function runChecks(Server $server): array
    {
        try {
            $pdo = $this->mysql->connect($server);
        } catch (ServerConnectionException $e) {
            return [new CheckResult('connection', 'MySQL connection', HealthStatus::Critical, "Can't connect: {$e->getMessage()}")];
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
