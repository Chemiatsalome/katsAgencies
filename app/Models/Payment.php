<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'tenancy_id', 'rent_charge_id', 'amount', 'payment_date',
    'method', 'collection_mode', 'reference_number', 'recorded_by', 'notes', 'source',
    'status', 'confirmed_at', 'confirmed_by', 'disputed_at', 'dispute_reason', 'disputed_by',
])]
class Payment extends Model
{
    use LogsActivity;

    /** Sources that represent an unverified historical guess, not a real transaction — safe to revert. */
    public const REVERTIBLE_SOURCES = ['opening_balance', 'seeded_data', 'historical_import'];

    /** Sources that represent a genuine transaction — must never be touched by the historical revert flow. */
    public const PROTECTED_SOURCES = ['system_payment', 'manual_entry'];

    public const STATUSES = ['awaiting_landlord_confirmation', 'confirmed', 'disputed'];

    /** Mirrors the DB column default so a freshly-created instance reflects 'confirmed' in memory too, without a reload. */
    protected $attributes = [
        'status' => 'confirmed',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    public function isRevertibleSource(): bool
    {
        return in_array($this->source, self::REVERTIBLE_SOURCES, true);
    }

    /** Not a real transaction — the system's own "opening balance" backfill guess, not something a tenant ever paid. */
    public function isOpeningBalance(): bool
    {
        return $this->source === 'opening_balance';
    }

    /** A tenant's own unverified claim of having paid offline — the only source that ever starts outside 'confirmed'. */
    public function isTenantSubmittedOffline(): bool
    {
        return $this->source === 'tenant_submitted_offline';
    }

    public function needsLandlordConfirmation(): bool
    {
        return $this->status === 'awaiting_landlord_confirmation';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function disputedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_landlord_confirmation');
    }

    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('status', 'disputed');
    }

    /**
     * Payments backed by an actual, confirmed transaction (recorded
     * historically or through the platform) — excludes `opening_balance`
     * (the system's own "we don't know, assume settled" guess, not real
     * cash) AND anything not yet (or no longer) 'confirmed', so a tenant's
     * unverified claim can never inflate a "collected rent" total or
     * month-over-month trend before a landlord actually confirms it.
     */
    public function scopeGenuine(Builder $query): Builder
    {
        return $query->where('source', '!=', 'opening_balance')->where('status', 'confirmed');
    }

    public function rentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class);
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenancy_id', 'rent_charge_id', 'amount', 'payment_date', 'method', 'reference_number'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
