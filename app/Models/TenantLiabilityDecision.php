<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tier 2 (design approved 2026-10-25): the only place an actual tenant-
 * liability decision for an Expense/MaintenanceTask is recorded. Occupancy
 * (Tenancy::occupyingOn()) is a candidate signal only and has no write path
 * of its own — nothing here is ever auto-created. `tenant_liable` and
 * `landlord_responsible` are not independent flags; they are the two
 * possible values of decision_type on whichever single row currently holds
 * the "active" (unreversed) slot for a decidable record — see
 * TenantLiabilityDecisionService, the only writer. Amendment is always a
 * reversal plus a fresh row (superseded_by_id linking back), never an
 * in-place edit.
 */
#[Fillable([
    'decidable_type', 'decidable_id', 'decision_type', 'tenancy_id',
    'decided_by', 'decided_at', 'reason', 'superseded_by_id',
])]
class TenantLiabilityDecision extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['decision_type', 'tenancy_id', 'reason', 'reversed_at', 'reversal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function decidable(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }

    public function isTenantLiable(): bool
    {
        return $this->decision_type === 'tenant_liable';
    }

    public function isLandlordResponsible(): bool
    {
        return $this->decision_type === 'landlord_responsible';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
