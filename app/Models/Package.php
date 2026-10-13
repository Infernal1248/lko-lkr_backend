<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'credits' => 'integer', 'sort_order' => 'integer', 'active' => 'boolean'];
    }
}
