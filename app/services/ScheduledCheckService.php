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
 * default 5) has passed gets its health checks. Certificates, on servers
 * and served directly, are checked every ssl_check_hours (default 24).
 *
 * A server whose SSH or MariaDB connection failed is retried less often
 * (twice the interval per consecutive failure, at most MAX_BACKOFF_MINUTES):
 * repeated failed logins could get the app banned by fail2ban or by
 * MariaDB's max_connect_errors. Only the app's key (or a stored password on
 * servers already set up for password login) is ever used; no new
 * credentials are tried.
 *
 * MariaDB logs of servers with SSH set up are imported too, every
 * mysql_log_import_minutes (an admin setting; 0 = off), reading only what's
 * new; skipped while a server is backing off.
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
        private ?MariadbLogService $logs = null,
        private readonly SettingsService $settings = new SettingsService(),
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

            if ($health || $ssl) {
                $this->checkIfDue($server, $health, $ssl && $this->sslDue($server, $now), $now, $log);
            }

            $this->importLogIfDue($server, $now, $log);
        }

        $direct = $this->ssl->checkDirectDue($now->copy()->subHours($this->sslHours())->addSeconds(30));

        if ($direct > 0) {
            $log[] = "direct: $direct certificate(s)";
        }

        return $log;
    }

    /**
     * @param list<string> $log
     */
    /**
     * @param bool $ssl whether the server's certificates are due (their own, daily, schedule)
     * @param list<string> $log
     */
    private function checkIfDue(Server $server, bool $health, bool $ssl, Carbon $now, array &$log): void
    {
        $health = $health && $this->isDue($server, true, $now);

        if (!$health && !$ssl) {
            return;
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

    /**
     * Import the server's MariaDB log when the interval has passed; not while
     * its connections are failing (see intervalMinutes()).
     *
     * @param list<string> $log
     */
    private function importLogIfDue(Server $server, Carbon $now, array &$log): void
    {
        $minutes = $this->settings->integer(SettingsService::MYSQL_LOG_IMPORT_MINUTES);

        // SSH failing: wait (fail2ban). Only MariaDB failing: import anyway, without logging into it.
        if ($minutes === 0 || !$server->mysql_enabled || !$server->sshReady() || $this->consecutiveConnectionFailures($server, ['ssh']) > 0) {
            return;
        }

        if ($server->log_imported_at !== null && $server->log_imported_at->copy()->addMinutes($minutes)->subSeconds(30)->greaterThan($now)) {
            return;
        }

        try {
            ($this->logs ??= new MariadbLogService())->importNow($server, useDatabase: $this->consecutiveConnectionFailures($server, ['connection']) === 0);
            $log[] = "{$server->name}: log import: {$server->fresh()?->log_import_message}";
        } catch (Throwable $e) {
            // Remember the failure for the report, and don't retry before the interval.
            $server->log_imported_at = $now;
            $server->log_import_message = mb_substr("The last scheduled import failed: {$e->getMessage()}", 0, 2000);
            $server->save();
            $log[] = "{$server->name}: log import failed: {$e->getMessage()}";
        }
    }

    /**
     * Certificates are checked every ssl_check_hours (an SSL setting, default
     * 24: once a day); expiry only moves day by day.
     */
    public function sslDue(Server $server, Carbon $now): bool
    {
        return $server->last_ssl_checked_at === null
            || $server->last_ssl_checked_at->copy()->addHours($this->sslHours())->subSeconds(30)->lessThanOrEqualTo($now);
    }

    private function sslHours(): int
    {
        return $this->settings->integer(SettingsService::SSL_CHECK_HOURS);
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

    /**
     * @param list<string> $keys the connection checks that count: 'ssh', 'connection' (MariaDB)
     */
    private function consecutiveConnectionFailures(Server $server, array $keys = self::CONNECTION_KEYS): int
    {
        $runs = StoredCheck::query()->where('server_id', $server->id)
            ->select('checked_at')->distinct()->orderByDesc('checked_at')->limit(8)->pluck('checked_at');
        $failures = 0;

        foreach ($runs as $time) {
            $failed = StoredCheck::query()->where('server_id', $server->id)->where('checked_at', $time)
                ->whereIn('check_key', $keys)->where('status', HealthStatus::Critical->value)->exists();

            if (!$failed) {
                break;
            }

            $failures++;
        }

        return $failures;
    }
}
