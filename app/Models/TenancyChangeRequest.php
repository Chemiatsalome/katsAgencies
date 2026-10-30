<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A tenant asking for something about their tenancy to change — rent,
 * due date, deposit, agreement details, the unit itself, or something else.
 * Accepting one never changes the tenancy by itself: the landlord still has
 * to make the actual change through whatever tool already handles it, then
 * document it. This row only ever records the ask and the decision.
 */
#[Fillable([
    'tenancy_id', 'category', 'current_value', 'requested_change', 'reason',
    'status', 'decided_by', 'decided_at', 'decision_note',
])]
class TenancyChangeRequest extends Model
{
    public const CATEGORIES = ['monthly_rent', 'rent_due_date', 'security_deposit', 'tenancy_agreement', 'property_unit_details', 'other'];

    public const STATUSES = ['pending', 'under_review', 'accepted', 'rejected'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'under_review'], true);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'under_review']);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenancy', fn (Builder $q) => $q->accessibleBy($user));
    }
}
