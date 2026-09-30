<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the MariaDB multi-server query tool. See MariadbQueryService.
 *
 * @property int $id
 * @property int $user_id
 * @property string $db_user the database login it ran as (typed by the user, never stored with a password)
 * @property list<int> $servers
 * @property string $statement
 * @property bool $writes whether it may change data (not a plain read)
 * @property string|null $outcome
 * @property bool $auth_failed
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class MariadbQuery extends Model
{
    protected $table = 'mariadb_queries';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'db_user', 'servers', 'statement', 'writes', 'outcome', 'auth_failed', 'created_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['servers' => 'array', 'writes' => 'boolean', 'auth_failed' => 'boolean'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
