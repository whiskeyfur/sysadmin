<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a certificate is served: a server's address and port, or directly
 * via DNS (server_id null). Holds the latest check result.
 *
 * @property int $id
 * @property int $certificate_id
 * @property int|null $server_id
 * @property int $port
 * @property Carbon|null $last_checked_at
 * @property string|null $last_status
 * @property string|null $last_summary
 * @property float|null $days_left
 * @property array<string, mixed>|null $details
 * @property-read SslCertificate $certificate
 * @property-read Server|null $server
 */
class SslBinding extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['certificate_id', 'server_id', 'port', 'last_checked_at', 'last_status', 'last_summary', 'days_left', 'details'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'certificate_id' => 'integer',
        'server_id' => 'integer',
        'port' => 'integer',
        'last_checked_at' => 'datetime',
        'days_left' => 'float',
        'details' => 'array',
    ];

    /**
     * @return BelongsTo<SslCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class, 'certificate_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function status(): ?HealthStatus
    {
        return $this->last_status === null ? null : HealthStatus::tryFrom($this->last_status);
    }

    /**
     * "web-1:8443", or "direct:443" when checked via DNS.
     */
    public function label(): string
    {
        return ($this->server === null ? 'direct' : $this->server->name) . ':' . $this->port;
    }
}
