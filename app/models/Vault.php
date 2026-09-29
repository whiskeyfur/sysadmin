<?php

namespace App\Models;

/**
 * The single vault row: the data key wrapped by the master key.
 *
 * @property int $id
 * @property string $wrapped_data_key
 */
class Vault extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['wrapped_data_key'];

    /**
     * @var list<string>
     */
    protected $hidden = ['wrapped_data_key'];
}
