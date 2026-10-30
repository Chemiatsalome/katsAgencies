<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A record that Kats Agencies paid a landlord out some of the rent it had
 * collected on their behalf via M-Pesa. Always manually recorded by an admin
 * after the actual transfer was made outside the system — this table is a
 * ledger, not a payment gateway integration.
 *
 * `status` tracks the landlord's own acknowledgement of a transfer they were
 * told about — it never feeds back into DisbursementService::owed(), which
 * moves the instant the row is recorded (the money already left Kats'
 * hands). Only an explicit admin reverse()/markFailed() changes what's
 * still owed; see DisbursementService for the full lifecycle.
 */
#[Fillable([
    'landlord_id', 'amount', 'disbursed_at', 'status', 'destination_type',
    'destination_shortcode', 'reference', 'notes', 'recorded_by',
    'payout_period_start', 'payout_period_end',
    'confirmed_at', 'confirmed_by', 'disputed_at', 'dispute_reason', 'disputed_by',
    'reversed_at', 'reversal_reason', 'reversed_by',
])]
class Disbursement extends Model
{
    use LogsActivity;

    public const STATUSES = ['awaiting_landlord_confirmation', 'confirmed', 'disputed', 'reversed', 'failed'];

    /** Statuses that represent money genuinely still out of Kats' hands — excluded are 'reversed' (admin undid it) and 'failed' (transfer never completed). */
    public const COUNTS_TOWARD_DISBURSED = ['awaiting_landlord_confirmation', 'confirmed', 'disputed'];

    /** Mirrors the DB column default so a freshly-created instance reflects it in memory too, without a reload. */
    protected $attributes = [
        'status' => 'awaiting_landlord_confirmation',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'disbursed_at' => 'date',
            'payout_period_start' => 'date',
            'payout_period_end' => 'date',
            'confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function needsLandlordConfirmation(): bool
    {
        return $this->status === 'awaiting_landlord_confirmation';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_landlord_confirmation');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('status', 'disputed');
    }

    /** Still counts toward the running "disbursed" total — everything except a reversed or failed transfer. */
    public function scopeCountsTowardDisbursed(Builder $query): Builder
    {
        return $query->whereIn('status', self::COUNTS_TOWARD_DISBURSED);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function disputedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['landlord_id', 'amount', 'disbursed_at', 'status', 'reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
