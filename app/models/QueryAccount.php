<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * A user's private database login for the MariaDB query tool. See QueryAccountService.
 *
 * @property int $id
 * @property int $user_id the owner, the only one who sees or uses it
 * @property string $label
 * @property string $username
 * @property string|null $password encrypted; use QueryAccountService::password()
 * @property list<int> $servers the servers it's for
 * @property array<int|string, string>|null $refused from the last test: server id => why it was refused
 * @property Carbon|null $tested_at
 * @property Carbon|null $last_used_at
 */
class QueryAccount extends Model
{
    protected $table = 'query_accounts';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'label', 'username', 'password', 'servers', 'refused', 'tested_at', 'last_used_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['servers' => 'array', 'refused' => 'array', 'tested_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function isFor(Server $server): bool
    {
        return in_array($server->id, array_map('intval', $this->servers ?? []), true);
    }

    public function refusedBy(Server $server): ?string
    {
        $why = ($this->refused ?? [])[$server->id] ?? ($this->refused ?? [])[(string) $server->id] ?? null;

        return is_string($why) ? $why : null;
    }
}
