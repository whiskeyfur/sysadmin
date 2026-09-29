<?php

namespace App\Services;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use App\Exceptions\AuthorizationException;
use App\Models\Server;
use App\Models\SslBinding;
use App\Models\SslCertificate;
use App\Models\SslCheck;
use App\Models\User;
use App\Utils\SystemClock;
use Carbon\Carbon;
use DomainException;
use Psr\Clock\ClockInterface;

/**
 * SSL monitoring. A certificate has a name and the hostnames it should
 * cover; bindings say where it's served: a server's address and port, or
 * directly via DNS. Several servers can serve the same certificate, and each
 * binding is checked separately: connect to the server, send the
 * certificate's first hostname as SNI, verify, and flag hostnames it doesn't
 * cover. Results are kept RETENTION_DAYS in ssl_checks.
 */
class SslMonitorService
{
    public const RETENTION_DAYS = 30;

    // ssl_checks.server_id for bindings checked directly via DNS (no server).
    private const DIRECT = 0;

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
     * Certificates with their bindings, worst first.
     *
     * @return list<SslCertificate>
     */
    public function certificates(): array
    {
        return SslCertificate::query()->with('bindings.server')->get()
            ->sortBy(fn (SslCertificate $c) => [-(HealthStatus::tryFrom((string) $c->last_status)?->severity() ?? -1), strtolower($c->name)])
            ->values()
            ->all();
    }

    /**
     * @return list<SslBinding>
     */
    public function bindingsFor(Server $server): array
    {
        return SslBinding::query()->with('certificate')->where('server_id', $server->id)->get()->sortBy('port')->values()->all();
    }

    /**
     * @param array<string, mixed> $input name, hostnames, notes; optionally server_id ('' = direct) and port for a first binding
     *
     * @throws DomainException with a user-facing message
     */
    public function createCertificate(User $admin, array $input): SslCertificate
    {
        $this->requireAdmin($admin);
        $certificate = new SslCertificate();
        $this->fill($certificate, $input);

        if (array_key_exists('port', $input)) {
            $this->addBinding($admin, $certificate, $this->server($input['server_id'] ?? null), $input['port']);
        }

        return $certificate;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateCertificate(User $admin, SslCertificate $certificate, array $input): void
    {
        $this->requireAdmin($admin);
        $this->fill($certificate, $input);
    }

    public function deleteCertificate(User $admin, SslCertificate $certificate): void
    {
        $this->requireAdmin($admin);

        $certificate->getConnection()->transaction(function () use ($certificate) {
            $bindingIds = $certificate->bindings()->pluck('id');
            SslCheck::query()->whereIn('binding_id', $bindingIds)->delete();
            SslBinding::query()->whereIn('id', $bindingIds)->delete();
            $certificate->delete();
        });
    }

    /**
     * Serve the certificate from a server (or directly via DNS when null) on a port.
     *
     * @throws DomainException
     */
    public function addBinding(User $admin, SslCertificate $certificate, ?Server $server, mixed $port): SslBinding
    {
        $this->requireAdmin($admin);
        $port = filter_var($port === '' || $port === null ? 443 : $port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if ($port === false) {
            throw new DomainException('The port must be a number from 1 to 65535.');
        }

        $exists = SslBinding::query()->where('certificate_id', $certificate->id)->where('port', $port)
            ->where(fn ($q) => $server === null ? $q->whereNull('server_id') : $q->where('server_id', $server->id))
            ->exists();

        if ($exists) {
            throw new DomainException(($server === null ? 'Direct' : $server->name) . ":$port is already listed for this certificate.");
        }

        return SslBinding::query()->create(['certificate_id' => $certificate->id, 'server_id' => $server?->id, 'port' => $port]);
    }

    public function removeBinding(User $admin, SslBinding $binding): void
    {
        $this->requireAdmin($admin);

        $binding->getConnection()->transaction(function () use ($binding) {
            SslCheck::query()->where('binding_id', $binding->id)->delete();
            $binding->delete();
        });

        $this->refreshSummaries($binding->certificate, $binding->server);
    }

    /**
     * @return int number of bindings checked
     */
    public function checkCertificate(User $user, SslCertificate $certificate): int
    {
        return $this->checkBindings($user, $certificate->bindings()->with(['certificate', 'server'])->get()->all());
    }

    /**
     * @return int number of bindings checked
     */
    public function checkServer(User $user, Server $server): int
    {
        return $this->checkBindings($user, SslBinding::query()->with(['certificate', 'server'])->where('server_id', $server->id)->get()->all());
    }

    /**
     * @return int number of bindings checked
     */
    public function checkAll(User $user): int
    {
        return $this->checkBindings($user, SslBinding::query()->with(['certificate', 'server'])->get()->all());
    }

    /**
     * Recent results for a binding, oldest first.
     *
     * @return list<SslCheck>
     */
    public function history(SslBinding $binding, int $runs = 24): array
    {
        return SslCheck::query()->where('binding_id', $binding->id)->get()
            ->sortByDesc('checked_at')->take($runs)->sortBy('checked_at')->values()->all();
    }

    /**
     * Turn per-server SSL host lists from before certificates existed into
     * certificates (one per hostname) bound to the same server and port.
     * Idempotent.
     */
    public function convertLegacy(): void
    {
        foreach (Server::query()->where('ssl_enabled', true)->get() as $server) {
            foreach ($server->sslTargets() as $target) {
                $certificate = SslCertificate::query()->where('name', $target['host'])->first()
                    ?? SslCertificate::query()->create(['name' => $target['host'], 'hostnames' => $target['host']]);

                $exists = SslBinding::query()->where('certificate_id', $certificate->id)->where('server_id', $server->id)->where('port', $target['port'])->exists();

                if (!$exists) {
                    SslBinding::query()->create(['certificate_id' => $certificate->id, 'server_id' => $server->id, 'port' => $target['port']]);
                }
            }

            $server->ssl_enabled = false;
            $server->ssl_hosts = null;
            $server->save();
        }
    }

    /**
     * @param list<SslBinding> $bindings
     */
    private function checkBindings(User $user, array $bindings): int
    {
        if (!$user->isAdmin()) {
            throw new AuthorizationException('Only admins can run SSL checks.');
        }

        $now = Carbon::instance($this->clock->now())->startOfSecond();
        $certificates = [];
        $servers = [];

        foreach ($bindings as $binding) {
            $certificate = $binding->certificate;
            $result = $this->checker->check(
                $certificate->primaryHostname(),
                $binding->port,
                $binding->server?->hostname,
                $certificate->hostnameList(),
            );
            $this->store($binding, $result, $now);
            $certificates[$certificate->id] = $certificate;

            if ($binding->server !== null) {
                $servers[$binding->server->id] = $binding->server;
            }
        }

        foreach ($certificates as $certificate) {
            $this->refreshSummaries($certificate, null);
        }

        foreach ($servers as $server) {
            $this->refreshSummaries(null, $server);
        }

        SslCheck::query()->where('checked_at', '<', $now->copy()->subDays(self::RETENTION_DAYS))->delete();

        return count($bindings);
    }

    private function store(SslBinding $binding, CheckResult $result, Carbon $now): void
    {
        $binding->last_checked_at = $now;
        $binding->last_status = $result->status->value;
        $binding->last_summary = mb_substr($result->summary, 0, 2000);
        $binding->days_left = $result->value;
        $binding->details = $result->details === [] ? null : $result->details;
        $binding->save();

        SslCheck::query()->create([
            'binding_id' => $binding->id,
            'server_id' => $binding->server_id ?? self::DIRECT,
            'host' => $binding->certificate->primaryHostname(),
            'port' => $binding->port,
            'status' => $result->status,
            'summary' => $binding->last_summary,
            'days_left' => $result->value,
            'details' => $binding->details,
            'checked_at' => $now,
        ]);
    }

    /**
     * Recompute the worst status (and last check time) of a certificate's or
     * a server's bindings.
     */
    private function refreshSummaries(?SslCertificate $certificate, ?Server $server): void
    {
        if ($certificate !== null) {
            $bindings = SslBinding::query()->where('certificate_id', $certificate->id)->get();
            $certificate->last_status = $this->worst($bindings->pluck('last_status')->all());
            $certificate->last_checked_at = $bindings->max('last_checked_at');
            $certificate->save();
        }

        if ($server !== null) {
            $bindings = SslBinding::query()->where('server_id', $server->id)->get();
            $server->last_ssl_status = $this->worst($bindings->pluck('last_status')->all());
            $server->last_ssl_checked_at = $bindings->max('last_checked_at');
            $server->save();
        }
    }

    /**
     * @param list<string|null> $statuses
     */
    private function worst(array $statuses): ?string
    {
        $known = array_filter(array_map(fn (?string $s) => $s === null ? null : HealthStatus::tryFrom($s), $statuses));

        return $known === [] ? null : HealthStatus::worst($known)->value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fill(SslCertificate $certificate, array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        $hostnames = [];

        foreach (preg_split('/[\s,]+/', strtolower(trim((string) ($input['hostnames'] ?? '')))) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            $host = (string) preg_replace('#^https://|[:/].*$#', '', $entry);
            $bare = str_starts_with($host, '*.') ? substr($host, 2) : $host;

            if (filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || !str_contains($bare, '.') && $bare !== 'localhost') {
                throw new DomainException("\"$entry\" isn't a hostname.");
            }

            $hostnames[] = $host;
        }

        $hostnames = array_values(array_unique($hostnames));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Give the certificate a name of up to 100 characters.');
        }

        if (SslCertificate::query()->where('name', $name)->where('id', '!=', $certificate->id ?? 0)->exists()) {
            throw new DomainException("There is already a certificate called $name.");
        }

        if ($hostnames === [] || str_starts_with($hostnames[0], '*.')) {
            throw new DomainException('List the hostnames it covers, starting with a real hostname (not a wildcard); it is used to ask the server for the certificate.');
        }

        $certificate->name = $name;
        $certificate->hostnames = implode("\n", $hostnames);
        $certificate->notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000) ?: null;
        $certificate->save();
    }

    private function server(mixed $id): ?Server
    {
        $id = filter_var($id, FILTER_VALIDATE_INT) ?: null;

        if ($id === null) {
            return null;
        }

        $server = Server::query()->find($id);

        if (!$server instanceof Server) {
            throw new DomainException('That server no longer exists.');
        }

        return $server;
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can manage SSL certificates.');
        }
    }
}
