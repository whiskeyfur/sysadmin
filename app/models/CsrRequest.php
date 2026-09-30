<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certificate signing request made by the CSR tool. See CsrService.
 *
 * @property int $id
 * @property int $user_id the admin
 * @property int|null $certificate_id the monitored certificate it replaces
 * @property string $common_name
 * @property list<string> $names SAN, the common name first
 * @property array<string, string> $subject O, OU, L, ST, C, emailAddress
 * @property string $key_type rsa or ec
 * @property int $key_size bits
 * @property string $key_source "new" (kept, encrypted) or "provided" (not kept)
 * @property string $csr PEM
 * @property string|null $private_key encrypted PEM (SecretCipher, csr-private-key:<id>)
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read SslCertificate|null $certificate
 */
class CsrRequest extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'certificate_id', 'common_name', 'names', 'subject', 'key_type', 'key_size', 'key_source', 'csr', 'private_key', 'created_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['private_key'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['names' => 'array', 'subject' => 'array', 'key_size' => 'integer', 'created_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SslCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class, 'certificate_id');
    }

    public function keyLabel(): string
    {
        return $this->key_type === 'ec' ? 'EC ' . ($this->key_size === 384 ? 'P-384' : 'P-256') : "RSA {$this->key_size}";
    }
}
