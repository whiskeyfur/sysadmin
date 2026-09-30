<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change the database account manager made on one server. See DbUserManagerService.
 *
 * @property int $id
 * @property int $user_id the admin
 * @property int $server_id
 * @property string $action create, password, grant, revoke or drop
 * @property string $account 'user'@'host'
 * @property string|null $detail
 * @property bool $ok
 * @property string|null $message
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Server|null $server
 */
class DbUserChange extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'server_id', 'action', 'account', 'detail', 'ok', 'message', 'created_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['ok' => 'boolean', 'created_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
