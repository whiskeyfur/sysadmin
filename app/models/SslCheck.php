<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored SSL certificate check.
 *
 * @property int $id
 * @property int|null $binding_id
 * @property int $server_id
 * @property string $host
 * @property int $port
 * @property HealthStatus $status
 * @property string $summary
 * @property float|null $days_left
 * @property array<string, mixed>|null $details
 * @property Carbon $checked_at
 * @property-read SslBinding|null $binding
 */
class SslCheck extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['binding_id', 'server_id', 'host', 'port', 'status', 'summary', 'days_left', 'details', 'checked_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'port' => 'integer',
        'status' => HealthStatus::class,
        'days_left' => 'float',
        'details' => 'array',
        'checked_at' => 'datetime',
    ];

    /**
     * Where the certificate was checked (null for rows from before certificates existed).
     *
     * @return BelongsTo<SslBinding, $this>
     */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(SslBinding::class, 'binding_id');
    }

    public function label(): string
    {
        return $this->port === 443 ? $this->host : "{$this->host}:{$this->port}";
    }

    /**
     * The host that was contacted: the server's hostname, or (served directly) the certificate's hostname
     * via DNS. Checks from before this was recorded fall back to what the binding says.
     */
    public function contacted(): string
    {
        $recorded = $this->details['contacted'] ?? null;

        return is_string($recorded) && $recorded !== '' ? $recorded : ($this->binding?->server->hostname ?? $this->host);
    }

    /**
     * The address the connection reached (e.g. one of several behind round-robin DNS), when recorded.
     */
    public function address(): ?string
    {
        $address = $this->details['address'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * Where the certificate came from, for people: "shop.example.com (203.0.113.7):8443".
     */
    public function origin(): string
    {
        $contacted = $this->contacted();
        $address = $this->address();
        $where = $address === null || $address === $contacted ? $contacted : "$contacted ($address)";

        return $this->port === 443 ? $where : "$where:{$this->port}";
    }
}
