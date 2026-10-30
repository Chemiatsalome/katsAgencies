<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the Tier 2 rent-credit ledger — a traceable slice of a single
 * source Payment's surplus applied to a single target RentCharge's
 * shortfall. Rows are never edited after creation except the reversal
 * fields (reversed_at/reversed_by/reversal_reason); a correction is always
 * a reversal plus a fresh row, never an in-place edit. See
 * RentCreditAllocationService for every writer — nothing else is permitted
 * to create or reverse a row directly.
 */
#[Fillable([
    'allocation_group_id', 'tenancy_id', 'source_payment_id', 'source_rent_charge_id',
    'target_rent_charge_id', 'amount', 'attribution_basis', 'created_by', 'notes',
])]
class RentCreditAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function sourceRentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class, 'source_rent_charge_id');
    }

    public function targetRentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class, 'target_rent_charge_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }
}
