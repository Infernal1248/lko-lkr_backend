<?php

namespace App\Models;

use App\Services\RegistryData;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $guarded = [];

    protected $appends = ['registry_complete'];

    public function getRegistryCompleteAttribute(): bool
    {
        return RegistryData::complete($this->registry);
    }

    protected $table = 'cards';

    protected function casts(): array
    {
        return ['registry' => 'encrypted:array', 'rights_confirmed_at' => 'datetime', 'data' => 'encrypted:array'];
    }
}
