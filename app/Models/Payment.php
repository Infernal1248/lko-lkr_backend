<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected $table = 'payments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['credits' => 'integer', 'amount' => 'integer'];
    }
}
