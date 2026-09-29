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
}
