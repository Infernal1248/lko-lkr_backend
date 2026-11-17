<?php

namespace App\Models;

use App\Services\RegistryData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected $guarded = [];

    protected $appends = ['registry_complete'];

    public function getRegistryCompleteAttribute(): bool
    {
        return RegistryData::complete($this->registry);
    }

    protected $table = 'cards';

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class, 'employee_id');
    }

    protected function casts(): array
    {
        return ['registry' => 'encrypted:array', 'rights_confirmed_at' => 'datetime', 'data' => 'encrypted:array'];
    }
}
