<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'tenant_id', 'house_id', 'move_in_date', 'move_out_date', 'lease_end_date', 'notice_period_days', 'monthly_rent',
    'deposit_required', 'deposit_paid', 'rent_due_day', 'status', 'notice_date', 'termination_reason', 'notes',
])]
class Tenancy extends Model
{
    use LogsActivity;

    /** Statuses under which the tenant is still physically occupying the house. */
    public const OCCUPYING_STATUSES = ['active', 'expiring_soon', 'notice_given'];

    protected function casts(): array
    {
        return [
            'move_in_date' => 'date',
            'move_out_date' => 'date',
            'lease_end_date' => 'date',
            'notice_date' => 'date',
            'notice_period_days' => 'integer',
            'monthly_rent' => 'decimal:2',
            'deposit_required' => 'decimal:2',
            'deposit_paid' => 'decimal:2',
            'rent_due_day' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function rentCharges(): HasMany
    {
        return $this->hasMany(RentCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function depositPayments(): HasMany
    {
        return $this->hasMany(DepositPayment::class);
    }

    public function depositRefunds(): HasMany
    {
        return $this->hasMany(DepositRefund::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** The day of the month rent is due for this tenancy, falling back to the tenant's default. */
    public function dueDay(): int
    {
        return (int) ($this->rent_due_day ?? $this->tenant->payment_due_day);
    }

    public function balance(): float
    {
        return (float) $this->rentCharges()
            ->where('status', '!=', 'paid')
            ->get()
            ->sum(fn (RentCharge $charge) => $charge->amount_expected - $charge->amount_paid);
    }

    public function depositBalance(): float
    {
        return (float) $this->deposit_required - (float) $this->deposit_paid;
    }

    public function depositRefundedTotal(): float
    {
        return (float) $this->depositRefunds()->sum('amount');
    }

    public function depositDeductedTotal(): float
    {
        return (float) $this->depositRefunds()->sum('deductions');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::OCCUPYING_STATUSES);
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereIn('status', self::OCCUPYING_STATUSES)
            ->whereNotNull('lease_end_date')
            ->whereBetween('lease_end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    /**
     * Tenancies that were actually occupying their house at any point during
     * the given calendar month, based on move-in/move-out dates rather than
     * the current `status` flag — this is what "was this tenant active in
     * March 2025" should mean, independent of where the tenancy stands today.
     */
    public function scopeActiveDuring(Builder $query, int $year, int $month): Builder
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $query
            ->where('move_in_date', '<=', $end)
            ->where(function (Builder $q) use ($start) {
                $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $start);
            });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'monthly_rent', 'move_out_date', 'lease_end_date', 'termination_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
