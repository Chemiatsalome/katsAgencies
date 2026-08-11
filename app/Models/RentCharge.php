<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'tenancy_id', 'period_year', 'period_month', 'due_date',
    'amount_expected', 'amount_paid', 'status', 'notes',
])]
class RentCharge extends Model
{
    /**
     * Marker left on an auto-generated "assumed paid" Payment (see
     * RentScheduleService) for a historical period, so it can be told apart
     * from a real payment a landlord entered, and reversed if wrong.
     */
    public const ASSUMED_PAID_NOTE = 'Assumed paid — period predates this tenancy\'s tracked history on the system.';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount_expected' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** True when this charge's "paid" status only exists because the system
     * assumed a historical (pre-current-year) period was paid — not because
     * a landlord actually recorded a payment. See RentScheduleService.
     */
    public function isAssumedPaid(): bool
    {
        return $this->payments->contains(fn (Payment $p) => $p->notes === self::ASSUMED_PAID_NOTE);
    }

    /**
     * True when every payment behind this charge's "paid" status is a
     * historical assumption (opening balance / seeded / imported) rather
     * than a genuine transaction — i.e. it's safe to revert to unpaid.
     * False for a charge with no payments at all, or one where at least one
     * payment is a protected/genuine source (never partially revert a mix).
     */
    public function isRevertible(): bool
    {
        if ($this->payments->isEmpty()) {
            return false;
        }

        return $this->payments->every(fn (Payment $p) => $p->isRevertibleSource());
    }

    /**
     * Classifies this charge for display — distinguishes a genuine platform
     * transaction from a historical assumption from real, current arrears.
     * $financialStartDate should be computed once per render (via
     * RentScheduleService::financialStartDate()) and passed in, not resolved
     * per-row, to avoid a lookup per charge in a list.
     *
     * @return 'advance'|'partial'|'system_payment'|'historical_settled'|'historical_unpaid'|'current_unpaid'
     */
    public function historicalClassification(Carbon $financialStartDate): string
    {
        if ($this->status === 'advance') {
            return 'advance';
        }

        if ($this->status === 'partial') {
            return 'partial';
        }

        if ($this->status === 'paid') {
            return $this->isRevertible() ? 'historical_settled' : 'system_payment';
        }

        // unpaid / overdue
        $periodStart = Carbon::create($this->period_year, $this->period_month, 1);

        return $periodStart->lt($financialStartDate) ? 'historical_unpaid' : 'current_unpaid';
    }

    public function recalculateStatus(): void
    {
        $paid = (float) $this->payments()->sum('amount');

        $status = match (true) {
            $paid > (float) $this->amount_expected => 'advance',
            $paid >= (float) $this->amount_expected && $paid > 0 => 'paid',
            $paid > 0 => 'partial',
            $this->due_date instanceof Carbon && $this->due_date->isPast() => 'overdue',
            default => 'unpaid',
        };

        $this->forceFill(['amount_paid' => $paid, 'status' => $status])->save();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'overdue');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        // A charge only counts as outstanding once it's actually due — a
        // pre-generated future month (e.g. from a reporting page backfilling
        // a whole year) isn't rent owed yet, so it must not inflate arrears.
        return $query->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->where('due_date', '<=', now());
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }
}
