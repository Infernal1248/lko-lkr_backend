<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected $hidden = ['npd_payload', 'npd_shop_id', 'npd_receipt_id', 'npd_deferred_until', 'npd_excluded'];

    protected $table = 'payments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['npd_payload' => 'encrypted:array', 'npd_excluded' => 'boolean', 'npd_deferred_until' => 'datetime', 'buyer' => 'encrypted:array', 'receipt_url' => 'encrypted', 'test_mode' => 'boolean', 'paid_at' => 'datetime', 'receipt_recorded_at' => 'datetime', 'credits' => 'integer', 'amount' => 'integer'];
    }
}
