<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'username', 'password', 'organization'];

    protected $hidden = ['password', 'remember_token', 'legacy_email'];

    public function getEmailForPasswordReset(): string
    {
        // This is the reset-token storage key; notification delivery still uses $this->email.
        return 'account:'.$this->getKey();
    }

    protected function casts(): array
    {
        return ['is_support_admin' => 'boolean', 'privacy_accepted_at' => 'datetime', 'onboarding_seen_at' => 'datetime', 'email_verified_at' => 'datetime', 'password' => 'hashed', 'organization' => 'encrypted:array', 'credits' => 'integer'];
    }
}
