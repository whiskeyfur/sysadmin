<?php

namespace App\Services;

use App\Enums\HealthStatus;
use App\Models\HealthCheck as StoredCheck;
use App\Models\Server;
use App\Models\SslBinding;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Scheduled checks, run by `php leaf app:run-checks` (cron every minute, or
 * --loop): every server whose interval (servers.check_interval_minutes,
 * default 5) has passed gets its health and SSL checks, and certificates
 * served directly are checked on the same interval.
 *
 * A server whose SSH or MariaDB connection failed is retried less often
 * (twice the interval per consecutive failure, at most MAX_BACKOFF_MINUTES):
 * repeated failed logins could get the app banned by fail2ban or by
 * MariaDB's max_connect_errors. Only the app's key (or a stored password on
 * servers already set up for password login) is ever used; no new
 * credentials are tried.
 */
class ScheduledCheckService
{
    public const DEFAULT_INTERVAL_MINUTES = 5;

    public const MAX_BACKOFF_MINUTES = 60;

    private const CONNECTION_KEYS = ['ssh', 'connection'];

    public function __construct(
        private readonly HealthCheckService $health = new HealthCheckService(),
        private readonly SslMonitorService $ssl = new SslMonitorService(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Run everything that's due.
     *
     * @return list<string> one line per server or certificate group checked
     */
    public function runDue(): array
    {
        $log = [];
        $now = Carbon::instance($this->clock->now());

        foreach (Server::query()->get()->sortBy('name') as $server) {
            $health = $this->health->canCheck($server);
            $ssl = SslBinding::query()->where('server_id', $server->id)->exists();

            if ((!$health && !$ssl) || !$this->isDue($server, $health, $now)) {
                continue;
            }

            try {
                $results = $health ? $this->health->runNow($server) : [];
                $certificates = $ssl ? $this->ssl->checkServer(null, $server) : 0;
                $worst = $results === [] ? null : HealthStatus::worst(array_map(fn ($r) => $r->status, $results))->value;
                $log[] = "{$server->name}: " . implode(', ', array_filter([
                    $health ? count($results) . " checks ($worst)" : null,
                    $ssl ? "$certificates certificate(s)" : null,
                ]));
            } catch (Throwable $e) {
                $log[] = "{$server->name}: failed: {$e->getMessage()}";
            }
        }

        $direct = $this->ssl->checkDirectDue($now->copy()->subMinutes(self::DEFAULT_INTERVAL_MINUTES)->addSeconds(30));

        if ($direct > 0) {
            $log[] = "direct: $direct certificate(s)";
        }

        return $log;
    }

    /**
     * Whether the server's interval (stretched after connection failures) has passed.
     */
    public function isDue(Server $server, bool $health, Carbon $now): bool
    {
        $last = $health ? $server->last_checked_at : $server->last_ssl_checked_at;

        if ($last === null) {
            return true;
        }

        $minutes = $this->intervalMinutes($server, $health);

        // A little slack, so a run a few seconds early (cron jitter) still counts.
        return $last->copy()->addMinutes($minutes)->subSeconds(30)->lessThanOrEqualTo($now);
    }

    /**
     * The interval, doubled for each consecutive run whose SSH or MariaDB
     * connection failed, up to MAX_BACKOFF_MINUTES.
     */
    public function intervalMinutes(Server $server, bool $health = true): int
    {
        $interval = max(1, (int) ($server->check_interval_minutes ?: self::DEFAULT_INTERVAL_MINUTES));

        if (!$health) {
            return $interval;
        }

        $failures = $this->consecutiveConnectionFailures($server);

        return $failures === 0 ? $interval : min(self::MAX_BACKOFF_MINUTES, $interval * 2 ** min($failures, 6));
    }

    private function consecutiveConnectionFailures(Server $server): int
    {
        $runs = StoredCheck::query()->where('server_id', $server->id)
            ->select('checked_at')->distinct()->orderByDesc('checked_at')->limit(8)->pluck('checked_at');
        $failures = 0;

        foreach ($runs as $time) {
            $failed = StoredCheck::query()->where('server_id', $server->id)->where('checked_at', $time)
                ->whereIn('check_key', self::CONNECTION_KEYS)->where('status', HealthStatus::Critical->value)->exists();

            if (!$failed) {
                break;
            }

            $failures++;
        }

        return $failures;
    }
}
