<?php

namespace App\Services;

use App\Models\ApacheVhost;
use App\Models\SslCertificate;

/**
 * The Apache virtual hosts found on all servers (ApacheService keeps them
 * in step with each configuration scan), and which of them SSL monitoring
 * covers.
 */
class VhostService
{
    /**
     * Every vhost, by server then name.
     *
     * @return list<ApacheVhost>
     */
    public function all(): array
    {
        return ApacheVhost::query()->with('server')->get()
            ->sortBy(fn (ApacheVhost $v) => strtolower(($v->server->name ?? '') . "\0" . ($v->name ?? "\u{10FFFF}") . "\0" . $v->address))
            ->values()->all();
    }

    /**
     * For each vhost (by id), the certificate in SSL monitoring that covers
     * its name, if any.
     *
     * @param list<ApacheVhost> $vhosts
     * @return array<int, SslCertificate>
     */
    public function certificates(array $vhosts): array
    {
        $certificates = SslCertificate::query()->get();
        $covered = [];

        foreach ($vhosts as $vhost) {
            if ($vhost->name === null) {
                continue;
            }

            $certificate = $certificates->first(fn (SslCertificate $c) => collect($c->hostnameList())->contains(fn (string $pattern) => SslCheckService::covers($pattern, (string) $vhost->name)));

            if ($certificate instanceof SslCertificate) {
                $covered[$vhost->id] = $certificate;
            }
        }

        return $covered;
    }
}
