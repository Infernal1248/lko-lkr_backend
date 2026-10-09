<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterProfile extends Model
{
    protected $guarded = [];

    protected $table = 'printer_profiles';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
