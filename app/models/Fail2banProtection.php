<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * An address protected from banning on a server. See Fail2banService.
 *
 * @property int $id
 * @property int $server_id
 * @property string $ip
 * @property int|null $user_id who protected it
 * @property Carbon|null $created_at
 */
class Fail2banProtection extends Model
{
    public $timestamps = false;

    protected $table = 'fail2ban_protected';

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'ip', 'user_id', 'created_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['created_at' => 'datetime'];

    /**
     * @return list<string> a server's protected addresses
     */
    public static function ipsFor(Server $server): array
    {
        return array_values(array_map('strval', self::query()->where('server_id', $server->id)->orderBy('ip')->pluck('ip')->all()));
    }
}
