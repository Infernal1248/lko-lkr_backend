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
        return ['buyer' => 'encrypted:array', 'receipt_url' => 'encrypted', 'test_mode' => 'boolean', 'paid_at' => 'datetime', 'receipt_recorded_at' => 'datetime', 'credits' => 'integer', 'amount' => 'integer'];
    }
}
