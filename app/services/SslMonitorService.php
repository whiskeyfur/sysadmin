<?php

namespace App\Services;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
use App\Models\SslCheck;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Psr\Clock\ClockInterface;

/**
 * SSL monitoring for servers that have it turned on: checks each of the
 * server's HTTPS hosts, stores the results (kept RETENTION_DAYS) and records
 * the worst status on the server.
 */
class SslMonitorService
{
    public const RETENTION_DAYS = 30;

    private readonly SslCheckService $checker;

    /**
     * @param SslCheckService|null $checker defaults to one using the admin's warning period
     */
    public function __construct(
        ?SslCheckService $checker = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->checker = $checker ?? new SslCheckService(warningDays: (new SettingsService())->sslWarningDays());
    }

    /**
     * @return list<CheckResult>
     */
    public function check(User $user, Server $server): array
    {
        if (!$user->isAdmin()) {
            throw new AuthorizationException('Only admins can run SSL checks.');
        }

        $results = array_map(fn (array $target) => $this->checker->check($target['host'], $target['port']), $server->sslTargets());

        if ($results !== []) {
            $this->store($server, $results);
        }

        return $results;
    }

    /**
     * Check every server with SSL monitoring turned on.
     *
     * @return int the number of certificates checked
     */
    public function checkAll(User $user): int
    {
        $count = 0;

        foreach (Server::query()->where('ssl_enabled', true)->get() as $server) {
            $count += count($this->check($user, $server));
        }

        return $count;
    }

    /**
     * The latest result per host for one server, or for every server.
     *
     * @return list<SslCheck>
     */
    public function latest(?Server $server = null): array
    {
        $servers = $server === null ? Server::query()->where('ssl_enabled', true)->get() : collect([$server]);
        $latest = [];

        foreach ($servers as $each) {
            if ($each->last_ssl_checked_at === null) {
                continue;
            }

            $rows = SslCheck::query()
                ->where('server_id', $each->id)
                ->where('checked_at', $each->last_ssl_checked_at)
                ->get();

            foreach ($rows as $row) {
                $row->setRelation('server', $each);
                $latest[] = $row;
            }
        }

        usort($latest, fn (SslCheck $a, SslCheck $b) => [$b->status->severity(), $a->days_left] <=> [$a->status->severity(), $b->days_left]);

        return $latest;
    }

    /**
     * Recent results per host for a server, oldest first.
     *
     * @return array<string, list<SslCheck>> keyed by "host:port"
     */
    public function history(Server $server, int $runs = 24): array
    {
        $times = SslCheck::query()
            ->where('server_id', $server->id)
            ->distinct()
            ->orderByDesc('checked_at')
            ->limit($runs)
            ->pluck('checked_at');

        if ($times->isEmpty()) {
            return [];
        }

        $history = [];

        foreach (SslCheck::query()->where('server_id', $server->id)->where('checked_at', '>=', $times->last())->get()->sortBy(['checked_at', 'id']) as $row) {
            $history["{$row->host}:{$row->port}"][] = $row;
        }

        return $history;
    }

    /**
     * @param list<CheckResult> $results
     */
    private function store(Server $server, array $results): void
    {
        $now = Carbon::instance($this->clock->now())->startOfSecond();

        (new SslCheck())->getConnection()->transaction(function () use ($server, $results, $now) {
            foreach ($results as $result) {
                [, $host, $port] = explode(':', $result->key, 3);

                SslCheck::query()->create([
                    'server_id' => $server->id,
                    'host' => $host,
                    'port' => (int) $port,
                    'status' => $result->status,
                    'summary' => mb_substr($result->summary, 0, 2000),
                    'days_left' => $result->value,
                    'details' => $result->details === [] ? null : $result->details,
                    'checked_at' => $now,
                ]);
            }

            $server->last_ssl_checked_at = $now;
            $server->last_ssl_status = HealthStatus::worst(array_map(fn (CheckResult $r) => $r->status, $results))->value;
            $server->save();

            SslCheck::query()->where('checked_at', '<', $now->copy()->subDays(self::RETENTION_DAYS))->delete();
        });
    }
}
