<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * A client address checked against the blocklists. See BlocklistService.
 *
 * @property int $id
 * @property string $ip
 * @property string|null $sources the lists that name it, comma-separated; null: none
 * @property Carbon|null $checked_at
 */
class BlocklistIp extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['ip', 'sources', 'checked_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['checked_at' => 'datetime'];
}
