<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Carbon\Carbon;

/**
 * A stored SSL certificate check.
 *
 * @property int $id
 * @property int $server_id
 * @property string $host
 * @property int $port
 * @property HealthStatus $status
 * @property string $summary
 * @property float|null $days_left
 * @property array<string, mixed>|null $details
 * @property Carbon $checked_at
 */
class SslCheck extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'host', 'port', 'status', 'summary', 'days_left', 'details', 'checked_at'];

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

    public function label(): string
    {
        return $this->port === 443 ? $this->host : "{$this->host}:{$this->port}";
    }
}
