<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'from_support' => 'boolean'];
    }
}
