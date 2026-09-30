<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to this machine's Apache made through the app. See LocalApacheService.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $server_id null: this machine
 * @property string $action
 * @property string|null $target
 * @property bool $ok
 * @property string|null $output
 * @property string|null $previous a remote file's text before the change
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class ApacheAdminLog extends Model
{
    protected $table = 'apache_admin_log';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'server_id', 'action', 'target', 'ok', 'output', 'previous', 'created_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['user_id' => 'integer', 'server_id' => 'integer', 'ok' => 'boolean', 'created_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
