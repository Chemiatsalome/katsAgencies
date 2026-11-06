<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'tenancy_id', 'amount', 'payment_date', 'method', 'reference_number', 'recorded_by', 'notes',
    'status', 'confirmed_at', 'confirmed_by', 'disputed_at', 'dispute_reason', 'disputed_by',
])]
class DepositPayment extends Model
{
    use LogsActivity;

    public const STATUSES = ['awaiting_landlord_confirmation', 'confirmed', 'disputed'];

    /** Mirrors the DB column default so a freshly-created instance reflects it in memory too, without a reload. */
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

    /** A tenant's own unverified claim of having paid their deposit offline — the only path that ever starts outside 'confirmed'. */
    public function needsLandlordConfirmation(): bool
    {
        return $this->status === 'awaiting_landlord_confirmation';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_landlord_confirmation');
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
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

    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenancy_id', 'amount', 'payment_date', 'method', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
