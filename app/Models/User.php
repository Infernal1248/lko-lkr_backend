<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'organization'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['is_support_admin' => 'boolean', 'privacy_accepted_at' => 'datetime', 'onboarding_seen_at' => 'datetime', 'email_verified_at' => 'datetime', 'password' => 'hashed', 'organization' => 'encrypted:array', 'credits' => 'integer'];
    }
}
