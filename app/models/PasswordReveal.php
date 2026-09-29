<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record: an admin revealed an account's password.
 *
 * @property int $id
 * @property int $account_id
 * @property int $user_id
 * @property Carbon $revealed_at
 * @property-read User|null $user
 */
class PasswordReveal extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['account_id', 'user_id', 'revealed_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['revealed_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
