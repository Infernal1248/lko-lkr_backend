<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $guarded = [];

    protected $table = 'cards';

    protected function casts(): array
    {
        return ['data' => 'encrypted:array'];
    }
}
