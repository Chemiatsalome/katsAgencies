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
    'method', 'reference_number', 'recorded_by', 'notes', 'source',
])]
class Payment extends Model
{
    use LogsActivity;

    /** Sources that represent an unverified historical guess, not a real transaction — safe to revert. */
    public const REVERTIBLE_SOURCES = ['opening_balance', 'seeded_data', 'historical_import'];

    /** Sources that represent a genuine transaction — must never be touched by the historical revert flow. */
    public const PROTECTED_SOURCES = ['system_payment', 'manual_entry'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function isRevertibleSource(): bool
    {
        return in_array($this->source, self::REVERTIBLE_SOURCES, true);
    }

    /**
     * Payments backed by an actual transaction (recorded historically or
     * through the platform) — excludes `opening_balance`, the system's own
     * "we don't know, assume settled" guess, which is not real cash and must
     * never inflate a "collected rent" total or month-over-month trend.
     */
    public function scopeGenuine(Builder $query): Builder
    {
        return $query->where('source', '!=', 'opening_balance');
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
