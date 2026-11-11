<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function scopeWithUnreadReply(Builder $query): void
    {
        $query->withExists(['messages as has_unread_reply' => fn ($q) => $q->where('from_support', true)
            ->where(fn ($q) => $q->whereNull('support_tickets.user_read_at')->orWhereColumn('support_messages.created_at', '>', 'support_tickets.user_read_at'))]);
    }

    protected function casts(): array
    {
        return ['subject' => 'encrypted', 'user_read_at' => 'datetime'];
    }
}
