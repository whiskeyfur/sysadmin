<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * One request from an Apache access log. See ApacheService.
 *
 * @property int $id
 * @property int $server_id
 * @property string $source the access log file
 * @property Carbon $requested_at
 * @property string|null $client
 * @property string|null $vhost
 * @property string|null $method
 * @property string|null $path
 * @property string|null $protocol
 * @property int $status
 * @property int $bytes
 * @property string|null $referer
 * @property string|null $agent
 * @property int|null $duration_ms
 */
class ApacheAccessEntry extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'source', 'requested_at', 'client', 'vhost', 'method', 'path', 'protocol', 'status', 'bytes', 'referer', 'agent', 'duration_ms'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['requested_at' => 'datetime', 'status' => 'integer', 'bytes' => 'integer', 'duration_ms' => 'integer'];
}
