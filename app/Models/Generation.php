<?php

namespace App\Models;

use App\Services\RegistryData;
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

    protected function casts(): array
    {
        return ['registry' => 'encrypted:array', 'rights_confirmed_at' => 'datetime', 'card_number' => 'encrypted', 'assigned_at' => 'datetime', 'snapshot' => 'encrypted:array', 'pdf' => 'encrypted', 'deleted_at' => 'datetime'];
    }
}
