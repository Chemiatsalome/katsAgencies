<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An itemised charge against a tenancy's held security deposit — e.g. a
 * repair for damage the tenant caused. Recorded independently of any
 * refund, as soon as the landlord identifies it, not only at move-out.
 * Never deleted once created; a mistaken or disputed one is voided instead,
 * which excludes it from the held-balance math but keeps it on the record.
 */
#[Fillable([
    'tenancy_id', 'amount', 'reason', 'deduction_date', 'recorded_by',
    'voided_at', 'void_reason', 'voided_by',
    'disputed_at', 'dispute_reason', 'disputed_by', 'dispute_resolved_at',
])]
class DepositDeduction extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deduction_date' => 'date',
            'voided_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_resolved_at' => 'datetime',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function disputedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'disputed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Disputed and still waiting on the landlord to void or uphold it. */
    public function isDisputePending(): bool
    {
        return $this->disputed_at !== null && $this->dispute_resolved_at === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenancy_id', 'amount', 'reason', 'deduction_date', 'voided_at', 'void_reason', 'disputed_at', 'dispute_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
