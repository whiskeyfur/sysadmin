<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Apache virtual host found in a server's configuration. See ApacheService.
 *
 * @property int $id
 * @property int $server_id
 * @property string|null $name ServerName, or null when the block has none
 * @property string|null $aliases ServerAlias names, one per line
 * @property string $address the <VirtualHost> argument as written
 * @property int|null $port
 * @property bool $ssl
 * @property string|null $document_root
 * @property string|null $error_log null: the main error log
 * @property string|null $access_logs one per line
 * @property string|null $config_file
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property-read Server|null $server
 */
class ApacheVhost extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['server_id', 'name', 'aliases', 'address', 'port', 'ssl', 'document_root', 'error_log', 'access_logs', 'config_file', 'first_seen_at', 'last_seen_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'port' => 'integer',
        'ssl' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return list<string>
     */
    public function aliasList(): array
    {
        return self::lines($this->aliases);
    }

    /**
     * @return list<string>
     */
    public function accessLogList(): array
    {
        return self::lines($this->access_logs);
    }

    /**
     * Its name for lists: ServerName, else the address.
     */
    public function label(): string
    {
        return $this->name ?? "(no ServerName) {$this->address}";
    }

    /**
     * @return list<string>
     */
    private static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $text)), fn ($l) => $l !== ''));
    }
}
