<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Carbon\Carbon;

/**
 * A stored health check result.
 *
 * @property int $id
 * @property int $server_id
 * @property string $check_key
 * @property HealthStatus $status
 * @property string $summary
 * @property float|null $value
 * @property string|null $unit
 * @property array<string, mixed>|null $details
 * @property Carbon $checked_at
 */
class HealthCheck extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'check_key', 'status', 'summary', 'value', 'unit', 'details', 'checked_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => HealthStatus::class,
        'value' => 'float',
        'details' => 'array',
        'checked_at' => 'datetime',
    ];
}
