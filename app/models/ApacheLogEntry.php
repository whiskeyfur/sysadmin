<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * One imported Apache error log entry. See ApacheService.
 *
 * @property int $id
 * @property int $server_id
 * @property string $source the error log file
 * @property string $level "note", "warning", "error" or "crash"
 * @property Carbon $logged_at
 * @property string $message
 * @property string $hash
 */
class ApacheLogEntry extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'source', 'level', 'logged_at', 'message', 'hash'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'logged_at' => 'datetime',
    ];
}
