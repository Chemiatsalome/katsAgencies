<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'tenant_id', 'house_id', 'move_in_date', 'move_out_date', 'lease_end_date', 'notice_period_days', 'monthly_rent',
    'deposit_required', 'deposit_paid', 'deposit_request_sent_at', 'rent_due_day', 'due_day_requested_by_tenant', 'status', 'notice_date', 'termination_reason', 'notes',
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
            'due_day_requested_by_tenant' => 'boolean',
            'deposit_request_sent_at' => 'datetime',
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

    public function depositDeductions(): HasMany
    {
        return $this->hasMany(DepositDeduction::class);
    }

    public function dueDayChangeRequests(): HasMany
    {
        return $this->hasMany(DueDayChangeRequest::class);
    }

    public function pendingDueDayChangeRequest(): ?DueDayChangeRequest
    {
        return $this->relationLoaded('dueDayChangeRequests')
            ? $this->dueDayChangeRequests->firstWhere('status', 'pending')
            : $this->dueDayChangeRequests()->pending()->first();
    }

    /** The most recently decided (accepted/rejected) request — used to show a tenant why their last request was declined. */
    public function latestDecidedDueDayChangeRequest(): ?DueDayChangeRequest
    {
        return $this->relationLoaded('dueDayChangeRequests')
            ? $this->dueDayChangeRequests->whereNotNull('decided_at')->sortByDesc('decided_at')->first()
            : $this->dueDayChangeRequests()->whereNotNull('decided_at')->latest('decided_at')->first();
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function contract(): HasOne
    {
        // Ordered by id, not the default created_at — two contracts
        // generated in quick succession (e.g. in tests, or an immediate
        // regenerate) can share the same second-precision timestamp, which
        // would make the default latestOfMany() pick a row nondeterministically.
        return $this->hasOne(Contract::class)->latestOfMany('id');
    }

    /** True once a contract has actually been sent but the tenant hasn't signed it yet — a never-sent draft blocks nothing. */
    public function hasUnsignedContract(): bool
    {
        return $this->contract && $this->contract->status !== 'draft' && ! $this->contract->isSigned();
    }

    public function notices(): HasMany
    {
        return $this->hasMany(TenancyNotice::class);
    }

    /** The currently live notice, if any — issuing a new one supersedes whatever this returns. */
    public function activeNotice(): ?TenancyNotice
    {
        return $this->relationLoaded('notices')
            ? $this->notices->first(fn (TenancyNotice $n) => $n->isOpen())
            : $this->notices()->open()->latest('sequence_number')->first();
    }

    /** Permanent — never decremented by a withdrawal or supersession, since the policy cares how many notices were ever issued. */
    public function noticeCount(): int
    {
        return $this->relationLoaded('notices') ? $this->notices->count() : $this->notices()->count();
    }

    public function latestNotice(): ?TenancyNotice
    {
        return $this->relationLoaded('notices')
            ? $this->notices->sortByDesc('sequence_number')->first()
            : $this->notices()->latest('sequence_number')->first();
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(TenancyChangeRequest::class);
    }

    public function renewalRequests(): HasMany
    {
        return $this->hasMany(TenancyRenewalRequest::class);
    }

    /** The currently in-flight renewal request, if any — only one is allowed open at a time. */
    public function activeRenewalRequest(): ?TenancyRenewalRequest
    {
        return $this->renewalRequests()->open()->latest()->first();
    }

    /**
     * Where the open-ended review cycle counts from — the tenancy's own
     * start, unless a renewal has actually been approved since, in which
     * case the cycle restarts from that decision. Never touches
     * move_in_date itself; this is purely a read-time calculation.
     */
    public function renewalCycleAnchor(): Carbon
    {
        $lastApproved = $this->renewalRequests()->where('status', 'approved')->latest('decided_at')->first();

        return $lastApproved?->decided_at ?? $this->move_in_date;
    }

    /**
     * When a renewal review becomes relevant: for a fixed-term tenancy,
     * one month before the agreement's own end date; for an open-ended one,
     * a periodic cycle (default 2 years, landlord-configurable to 1) from
     * the cycle anchor above. Purely a reminder date — nothing here ever
     * changes the tenancy's actual terms.
     */
    public function renewalReviewDueDate(): ?Carbon
    {
        if ($this->lease_end_date) {
            return $this->lease_end_date->copy()->subDays(30);
        }

        $landlordId = $this->house?->block->property->landlord_id;
        $cycleYears = $landlordId ? (int) LandlordSetting::get($landlordId, 'renewal_review_cycle_years', 2) : 2;

        return $this->renewalCycleAnchor()->copy()->addYears($cycleYears);
    }

    /** True once a renewal is worth surfacing to the tenant — either one's already in flight, or the review window has opened. */
    public function renewalRelevant(): bool
    {
        if ($this->activeRenewalRequest()) {
            return true;
        }

        $due = $this->renewalReviewDueDate();

        return $due && now()->greaterThanOrEqualTo($due);
    }

    /** The day of the month rent is due for this tenancy, falling back to the tenant's default. */
    public function dueDay(): int
    {
        return (int) ($this->rent_due_day ?? $this->tenant->payment_due_day);
    }

    /**
     * When rent is next due, computed analytically rather than only from an
     * already-generated RentCharge row. A row for an upcoming period only
     * exists once RentScheduleService::ensureChargesForPeriod() has run for
     * it (a page visit or the nightly command) — without this, a tenant
     * paid up for the current month saw a blank "Next Rent Due" with
     * nothing wrong at all, simply because next month's row doesn't exist
     * yet. Returns null only when there's no due day to compute from.
     */
    public function nextRentDueDate(): ?Carbon
    {
        $outstanding = $this->rentCharges()->where('status', '!=', 'paid')->orderBy('due_date')->first();

        if ($outstanding) {
            return $outstanding->due_date;
        }

        $day = $this->dueDay();

        if ($day < 1) {
            return null;
        }

        $thisMonth = Carbon::create(now()->year, now()->month, min($day, now()->daysInMonth));

        return $thisMonth->isPast() ? $thisMonth->addMonthNoOverflow() : $thisMonth;
    }

    /** e.g. "5th", "1st", "22nd" — for display. */
    public static function ordinalDay(int $day): string
    {
        $suffix = match (true) {
            in_array($day, [1, 21, 31]) => 'st',
            in_array($day, [2, 22]) => 'nd',
            in_array($day, [3, 23]) => 'rd',
            default => 'th',
        };

        return "{$day}{$suffix}";
    }

    /**
     * What the tenant currently owes — charges that are actually due, not
     * ones already generated for a future month. Mirrors
     * RentCharge::scopeOutstanding()'s due_date cutoff so this agrees with
     * what the landlord's Arrears page and rent reminders consider owed;
     * without it a tenant could be shown (and asked to prepay) a not-yet-due
     * month that doesn't count as arrears anywhere else in the app.
     */
    public function balance(): float
    {
        return (float) $this->rentCharges()
            ->where('status', '!=', 'paid')
            ->where('due_date', '<=', now())
            ->get()
            ->sum(fn (RentCharge $charge) => $charge->amount_expected - $charge->amount_paid);
    }

    /**
     * A surplus sitting on a past charge the tenant overpaid (status =
     * 'advance') — never automatically moved onto a newer charge (that
     * would mean splitting or re-tagging a real Payment row, breaking its
     * receipt/audit trail), but real money this tenant has already handed
     * over and genuinely shouldn't be charged a late fee or counted as
     * arrears against, again, elsewhere on the same tenancy. Callers that
     * compute lateness/arrears should net against this before charging
     * anything new.
     */
    public function advanceCreditAvailable(): float
    {
        return (float) $this->rentCharges()
            ->where('status', 'advance')
            ->get()
            ->sum(fn (RentCharge $charge) => $charge->amount_paid - $charge->amount_expected);
    }

    /**
     * This tenancy's own currently-outstanding charges, oldest due first,
     * each annotated with how much of this SAME tenancy's advance credit
     * covers it — the same oldest-first consumption order
     * RentScheduleService::applyLateFees() already uses, so "is this
     * covered by credit" means the same thing everywhere it's asked.
     *
     * Purely a read-only breakdown: never writes to a RentCharge or Payment
     * row, and credit computed here can only ever reduce THIS tenancy's own
     * charges — advanceCreditAvailable() is scoped to $this->rentCharges(),
     * so there is no path for one tenancy's surplus to net against a
     * different tenancy's (or a different tenant's, or landlord's) balance.
     *
     * @return Collection<int, array{charge: RentCharge, gross: float, credit_applied: float, net: float}>
     */
    public function outstandingChargesWithCredit(): Collection
    {
        $remainingCredit = $this->advanceCreditAvailable();

        return $this->rentCharges()->outstanding()->orderBy('due_date')->get()
            ->map(function (RentCharge $charge) use (&$remainingCredit) {
                $gross = (float) $charge->amount_expected - (float) $charge->amount_paid;
                $covered = round(min($gross, max(0, $remainingCredit)), 2);
                $remainingCredit -= $covered;

                return [
                    'charge' => $charge,
                    'gross' => $gross,
                    'credit_applied' => $covered,
                    'net' => round($gross - $covered, 2),
                ];
            });
    }

    /**
     * How much of the required deposit is still unpaid — NOT how much is
     * currently held (see depositHeldBalance() for that). Kept as an alias
     * of depositOutstanding() since existing call sites already use this
     * name; new code should prefer the less ambiguous name.
     */
    public function depositBalance(): float
    {
        return $this->depositOutstanding();
    }

    public function depositOutstanding(): float
    {
        return max(0, (float) $this->deposit_required - (float) $this->deposit_paid);
    }

    public function depositRefundedTotal(): float
    {
        return (float) $this->depositRefunds()->sum('amount');
    }

    /**
     * Sum of active, undisputed deductions — a voided one never reduces the
     * held balance, and nor does one the tenant has disputed until the
     * landlord resolves that dispute (upholds or voids it).
     */
    public function depositDeductedTotal(): float
    {
        return (float) $this->depositDeductions()
            ->whereNull('voided_at')
            ->where(function ($q) {
                $q->whereNull('disputed_at')->orWhereNotNull('dispute_resolved_at');
            })
            ->sum('amount');
    }

    /** What's actually sitting held right now: paid in, minus active deductions, minus refunds already paid out. */
    public function depositHeldBalance(): float
    {
        return (float) $this->deposit_paid - $this->depositDeductedTotal() - $this->depositRefundedTotal();
    }

    /**
     * Where this tenancy's deposit stands in the paid → held → refunded
     * lifecycle. Only meaningful once the tenancy has actually ended — while
     * still occupying, the deposit is supposed to sit held, not refunded, so
     * calling it "not refunded" at that point would be a false alarm.
     */
    public function depositRefundStatus(): string
    {
        // Property access (not the depositPayments()/depositRefunds()/
        // depositDeductions() method calls the sibling helpers above use) so
        // an eager-loaded relation is reused instead of triggering a fresh
        // query — this gets called in a loop over every tenancy on the
        // Deposits index.
        $paid = (float) $this->depositPayments->sum('amount');
        $refunded = (float) $this->depositRefunds->sum('amount');
        $deducted = (float) $this->depositDeductions->whereNull('voided_at')->sum('amount');
        $held = $paid - $refunded - $deducted;
        $hasEnded = ! in_array($this->status, self::OCCUPYING_STATUSES, true) && $this->status !== 'pending';

        return match (true) {
            ! $hasEnded => 'holding',
            $paid <= 0 => 'no_deposit',
            $held <= 0 && $refunded > 0 => 'fully_refunded',
            $refunded > 0 && $held > 0 => 'partially_refunded',
            default => 'not_refunded',
        };
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::OCCUPYING_STATUSES);
    }

    /**
     * Tier 2 (design approved 2026-10-25): which tenancy occupied the given
     * house on a specific date — exact-date comparison, not the month-
     * granularity scopeActiveDuring(), because a tenant moving out and
     * another moving in within the same calendar month would both match at
     * month granularity, which is exactly the misattribution this exists to
     * avoid. Occupancy is a candidate signal only (see
     * TenantLiabilityDecisionService) — it has no write path and must never
     * be treated as proof of liability on its own.
     */
    public static function occupyingOn(int $houseId, \DateTimeInterface|string $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return self::where('house_id', $houseId)
            ->where('move_in_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $date));
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

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('house', fn (Builder $q) => $q->accessibleBy($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'monthly_rent', 'move_out_date', 'lease_end_date', 'termination_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
