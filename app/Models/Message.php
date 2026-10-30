<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One landlord→tenant message thread. Messages\Index::send() can fan a
 * single compose action out to many tenants at once (a whole property, a
 * block, everyone) — one Message row is created per resolved recipient, so
 * each tenant gets their own independently replyable thread even though
 * the original text was identical for all of them. Previously this was
 * just a bare AppNotification per tenant with nothing backing it, which is
 * exactly why neither side could ever reply — there was no thread for a
 * reply to belong to.
 */
#[Fillable(['sender_id', 'tenant_id', 'scope', 'subject', 'body'])]
class Message extends Model
{
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(MessageReply::class)->orderBy('created_at');
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn (Builder $q) => $q->accessibleBy($user));
    }
}
