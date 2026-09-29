<?php

namespace App\Models;

/**
 * One app-wide setting. Use SettingsService, which knows the defaults and
 * validates values.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value'];
}
