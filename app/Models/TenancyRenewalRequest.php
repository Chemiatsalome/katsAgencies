<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's request to renew their tenancy agreement — distinct from a
 * TenancyNotice's "extension of time to vacate": this is about renewing
 * the agreement itself, not responding to a notice. Approving one reuses
 * ContractService's own versioning (the same mechanism
 * TenancyNotice::decide('approve_extension') already uses), so a renewed
 * agreement always gets generated and superseded the same way regardless
 * of which workflow triggered it.
 */
#[Fillable([
    'tenancy_id', 'requested_duration_months', 'preferred_new_end_date', 'message', 'status',
    'alternative_duration_months', 'alternative_new_end_date', 'alternative_note',
    'decided_by', 'decided_at', 'decision_note', 'final_new_end_date', 'new_contract_id',
])]
class TenancyRenewalRequest extends Model
{
    public const STATUSES = ['pending', 'under_review', 'alternative_proposed', 'approved', 'declined'];

    public const OPEN_STATUSES = ['pending', 'under_review', 'alternative_proposed'];

    protected function casts(): array
    {
        return [
            'preferred_new_end_date' => 'date',
            'alternative_new_end_date' => 'date',
            'final_new_end_date' => 'date',
            'decided_at' => 'datetime',
            'requested_duration_months' => 'integer',
            'alternative_duration_months' => 'integer',
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

    public function newContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'new_contract_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
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
