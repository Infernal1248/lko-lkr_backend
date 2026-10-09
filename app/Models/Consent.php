<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $guarded = [];

    protected $table = 'consents';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['organization' => 'encrypted:array'];
    }
}
