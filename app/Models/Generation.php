<?php

namespace App\Models;

use App\Services\RegistryData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    protected $guarded = [];

    protected $appends = ['registry_complete'];

    public function getRegistryCompleteAttribute(): bool
    {
        return $this->card_number !== null && ! empty($this->snapshot['data']['issue_date']) && RegistryData::complete($this->registry);
    }

    protected $table = 'generations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['pdf', 'fingerprint', 'request_key', 'card_number_hash'];

    public function scopeCreatedPeriod(Builder $query, ?string $from, ?string $before): Builder
    {
        return $query
            ->when($from, fn ($query) => $query->where('created_at', '>=', Carbon::parse($from)->utc()->toDateTimeString()))
            ->when($before, fn ($query) => $query->where('created_at', '<', Carbon::parse($before)->utc()->toDateTimeString()));
    }

    protected function casts(): array
    {
        return ['inactive_at' => 'datetime', 'corrections' => 'encrypted:array', 'registry' => 'encrypted:array', 'rights_confirmed_at' => 'datetime', 'card_number' => 'encrypted', 'assigned_at' => 'datetime', 'snapshot' => 'encrypted:array', 'pdf' => 'encrypted', 'deleted_at' => 'datetime'];
    }
}
