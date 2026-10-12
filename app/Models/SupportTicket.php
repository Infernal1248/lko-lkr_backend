<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['subject' => 'encrypted', 'user_read_at' => 'datetime'];
    }
}
