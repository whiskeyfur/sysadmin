<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * Five minutes of one Apache access log, summarised. See ApacheService.
 *
 * @property int $id
 * @property int $server_id
 * @property string $log the access log file
 * @property Carbon $bucket_at the bucket's start (UTC)
 * @property int $requests
 * @property int $bytes
 * @property int $status_2xx
 * @property int $status_3xx
 * @property int $status_4xx
 * @property int $status_5xx
 */
class ApacheTraffic extends Model
{
    protected $table = 'apache_traffic';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'log', 'bucket_at', 'requests', 'bytes', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'bucket_at' => 'datetime',
        'requests' => 'integer',
        'bytes' => 'integer',
        'status_2xx' => 'integer',
        'status_3xx' => 'integer',
        'status_4xx' => 'integer',
        'status_5xx' => 'integer',
    ];
}
