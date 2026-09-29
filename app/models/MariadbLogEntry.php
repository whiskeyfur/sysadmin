<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * One imported MariaDB log entry. See MariadbLogService.
 *
 * @property int $id
 * @property int $server_id
 * @property string $source "error", "journal" or "slow"
 * @property string $level "note", "warning", "error", "crash" or "slow"
 * @property Carbon $logged_at
 * @property string $message
 * @property string $hash
 */
class MariadbLogEntry extends Model
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
