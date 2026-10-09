<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected $table = 'ledger_entries';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance' => 'integer'];
    }
}
