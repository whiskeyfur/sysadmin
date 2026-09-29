<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monitored SSL certificate: a name (label) and the hostnames it should
 * cover. Served by one or more bindings (server + port). Use SslMonitorService.
 *
 * @property int $id
 * @property string $name
 * @property string $hostnames one per line
 * @property string|null $notes
 * @property Carbon|null $last_checked_at
 * @property string|null $last_status worst status of its bindings at the last check
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SslBinding> $bindings
 */
class SslCertificate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'hostnames', 'notes', 'last_checked_at', 'last_status'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['last_checked_at' => 'datetime'];

    /**
     * @return HasMany<SslBinding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(SslBinding::class, 'certificate_id');
    }

    /**
     * @return list<string>
     */
    public function hostnameList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->hostnames) ?: [])));
    }

    /**
     * The hostname sent as SNI and checked against the certificate.
     */
    public function primaryHostname(): string
    {
        return $this->hostnameList()[0] ?? '';
    }
}
