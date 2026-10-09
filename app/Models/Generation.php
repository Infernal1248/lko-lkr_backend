<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    protected $guarded = [];

    protected $table = 'generations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['pdf', 'fingerprint', 'request_key', 'card_number_hash'];

    protected function casts(): array
    {
        return ['card_number' => 'encrypted', 'assigned_at' => 'datetime', 'snapshot' => 'encrypted:array', 'pdf' => 'encrypted', 'deleted_at' => 'datetime'];
    }
}
