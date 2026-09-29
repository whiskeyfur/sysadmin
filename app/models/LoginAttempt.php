<?php

namespace App\Models;

/**
 * One counted sign-in failure or registration, for rate limiting.
 *
 * @property int $id
 * @property string $bucket
 * @property int $attempted_at
 */
class LoginAttempt extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['bucket', 'attempted_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['attempted_at' => 'integer'];
}
