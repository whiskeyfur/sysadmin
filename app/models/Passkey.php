<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passkey or hardware security key a user signs in with. See PasskeyService.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_id base64url
 * @property string $credential_hash SHA-256 of the raw credential id (hex)
 * @property string $public_key PEM
 * @property int $sign_count
 * @property string $rp_id the host it was registered on
 * @property string|null $aaguid
 * @property bool $backup_eligible synced passkey (true) or device-bound key
 * @property Carbon $created_at
 * @property Carbon|null $last_used_at
 * @property-read User|null $user
 */
class Passkey extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'name', 'credential_id', 'credential_hash', 'public_key', 'sign_count', 'rp_id', 'aaguid', 'backup_eligible', 'created_at', 'last_used_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'sign_count' => 'integer',
        'backup_eligible' => 'boolean',
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
