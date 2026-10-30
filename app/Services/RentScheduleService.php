<?php

namespace App\Services;

use App\Models\LandlordSetting;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The single source of truth for turning tenancy records into monthly rent
 * obligations. A tenant's active tenancy is the source of truth for what
 * they owe — landlords (and the platform-wide admin aggregates) should never
 * need to manually create a RentCharge row per tenant per month.
 */
class RentScheduleService
{
    /**
     * Ensure every tenancy that was active during the given period has a
     * RentCharge row for it. Idempotent — safe to call on every page render.
     *
     * A charge newly generated for a period before the tenancy's landlord's
     * "financial start date" (see LandlordSetting key `financial_start_date`,
     * default: first day of the current month) is treated as an opening
     * balance — assumed settled, backed by an auto-generated Payment tagged
     * `source: opening_balance` so it's clearly distinguishable from a real
     * transaction and every other total (arrears, collection rate, admin
     * aggregates) stays consistent. There is no realistic way for a landlord
     * to retroactively enter years of historical payment history one-by-one,
     * and defaulting that gap to "overdue" would manufacture arrears that
     * were never real. A landlord can reverse this per-charge (or in bulk)
     * via revertHistoricalPayment() for any tenant who genuinely didn't pay.
     *
     * @return int Number of RentCharge rows newly created.
     */
    public function ensureChargesForPeriod(?int $landlordId, ?int $propertyId, int $year, int $month): int
    {
        $created = 0;
        $periodStart = Carbon::create($year, $month, 1);

        Tenancy::activeDuring($year, $month)
            ->with(['tenant', 'house.block.property'])
            ->when($landlordId, fn ($q) => $q->whereHas(
                'house.block.property',
                fn ($q2) => $q2->where('landlord_id', $landlordId)
            ))
            ->when($propertyId, fn ($q) => $q->whereHas(
                'house.block',
                fn ($q2) => $q2->where('property_id', $propertyId)
            ))
            ->each(function (Tenancy $tenancy) use ($year, $month, $periodStart, &$created) {
                $daysInMonth = Carbon::create($year, $month)->daysInMonth;
                $dueDate = Carbon::create($year, $month, min($tenancy->dueDay(), $daysInMonth));

                $charge = RentCharge::firstOrCreate(
                    ['tenancy_id' => $tenancy->id, 'period_year' => $year, 'period_month' => $month],
                    [
                        'due_date' => $dueDate,
                        'amount_expected' => $tenancy->monthly_rent,
                        'status' => $dueDate->isPast() ? 'overdue' : 'unpaid',
                    ]
                );

                if ($charge->wasRecentlyCreated) {
                    $created++;

                    $assumePaid = $periodStart->lt($this->financialStartDate($tenancy->house->block->property->landlord_id));

                    if ($assumePaid) {
                        Payment::create([
                            'tenancy_id' => $tenancy->id,
                            'rent_charge_id' => $charge->id,
                            'amount' => $charge->amount_expected,
                            'payment_date' => $dueDate,
                            'method' => 'other',
                            'notes' => RentCharge::ASSUMED_PAID_NOTE,
                            'source' => 'opening_balance',
                        ]);
                    } else {
                        // Tier 2 (design approved 2026-10-25): a brand-new
                        // charge may already be eligible for credit sitting
                        // on this same tenancy's own 'advance' charges —
                        // automatic-only allocation, no manual trigger (no
                        // created_by, system-originated). A no-op when
                        // there's no available credit.
                        app(RentCreditAllocationService::class)->applyCreditToCharge($charge);
                    }
                }
            });

        return $created;
    }

    /**
     * Adds a one-time late fee to every outstanding RentCharge that's past
     * its grace period — a no-op for any landlord who hasn't turned late
     * fees on in Settings. Idempotent per charge via late_fee_charged_at, so
     * it's safe to run more than once a day.
     *
     * Grace period depends on the tenancy: the landlord's configured grace
     * window for everyone on the standard due date, or zero days for a
     * tenant whose tenancy has due_day_requested_by_tenant set — they chose
     * their own due date in exchange for forfeiting the grace period (see
     * DueDayChangeRequest / Tenancies\Show::acceptDueDayChangeRequest()).
     *
     * @return int Number of charges a fee was just added to.
     */
    public function applyLateFees(?int $landlordId = null): int
    {
        $applied = 0;

        $landlordIds = $landlordId ? [$landlordId] : User::role('landlord')->pluck('id')->all();

        foreach ($landlordIds as $id) {
            if (LandlordSetting::get($id, 'late_fee_enabled', '0') !== '1') {
                continue;
            }

            $percentage = (float) LandlordSetting::get($id, 'late_fee_percentage', '5');
            $standardGraceDays = (int) LandlordSetting::get($id, 'late_fee_grace_days', '5');

            if ($percentage <= 0) {
                continue;
            }

            $charges = RentCharge::whereIn('status', ['unpaid', 'partial', 'overdue'])
                ->whereNull('late_fee_charged_at')
                ->whereHas('tenancy.house.block.property', fn ($q) => $q->where('landlord_id', $id))
                ->with('tenancy.tenant')
                ->orderBy('due_date')
                ->get()
                ->groupBy('tenancy_id');

            foreach ($charges as $tenancyCharges) {
                $tenancy = $tenancyCharges->first()->tenancy;

                // A surplus sitting on a different (already-'advance')
                // charge for this same tenancy — consumed oldest-charge-
                // first below, same ordering `allocatePayment()` uses for
                // an actual payment, so a tenant who overpaid last month
                // never gets charged a late fee on money they've already
                // handed over, just not yet credited to this charge.
                //
                // Read through RentCreditAllocationService::unallocatedCredit()
                // (Tier 2), not Tenancy::advanceCreditAvailable() directly —
                // the raw surplus never shrinks as formal allocations are
                // created elsewhere, so using it as-is here could net the
                // same credit against a late fee *and* have it already be
                // spent covering a different charge's shortfall.
                $remainingCredit = app(RentCreditAllocationService::class)->unallocatedCredit($tenancy);

                foreach ($tenancyCharges as $charge) {
                    // A brand-new tenant's very first charge is often generated
                    // with a due date already in the past relative to the
                    // calendar month (the due day can easily fall before the
                    // actual move-in day) — that's an artifact of how charges
                    // are generated, not the tenant actually being late. Late
                    // fees only start from the second billing period onward.
                    $isFirstPeriod = $charge->period_year === $tenancy->move_in_date->year
                        && $charge->period_month === $tenancy->move_in_date->month;

                    if ($isFirstPeriod) {
                        continue;
                    }

                    $graceDays = $tenancy->due_day_requested_by_tenant ? 0 : $standardGraceDays;

                    if (now()->lessThanOrEqualTo($charge->due_date->copy()->addDays($graceDays)->endOfDay())) {
                        continue;
                    }

                    $outstanding = (float) $charge->amount_expected - (float) $charge->amount_paid;

                    if ($outstanding <= 0) {
                        continue;
                    }

                    $covered = min($outstanding, $remainingCredit);
                    $remainingCredit -= $covered;
                    $netOutstanding = $outstanding - $covered;

                    if ($netOutstanding <= 0) {
                        // Fully covered by a credit already sitting elsewhere
                        // on this tenancy — not actually late.
                        continue;
                    }

                    $fee = round($netOutstanding * $percentage / 100, 2);

                    if ($fee <= 0) {
                        continue;
                    }

                    $periodLabel = Carbon::create($charge->period_year, $charge->period_month, 1)->format('F Y');
                    $graceDescription = $graceDays > 0
                        ? "{$graceDays}-day grace period passed"
                        : 'no grace period — this tenancy\'s due date was tenant-requested';

                    $charge->forceFill([
                        'amount_expected' => (float) $charge->amount_expected + $fee,
                        'late_fee_amount' => $fee,
                        'late_fee_charged_at' => now(),
                        'notes' => trim(($charge->notes ? $charge->notes."\n" : '')
                            ."Late fee of KSh ".number_format($fee, 2)." added ({$percentage}% of overdue amount, {$graceDescription})."),
                    ])->save();

                    $charge->recalculateStatus();

                    app(NotificationService::class)->notify(
                        $tenancy->tenant,
                        'late_fee_charged',
                        'Late Fee Applied',
                        "A late fee of KSh ".number_format($fee, 2)." was added to your {$periodLabel} rent because it was still unpaid after the due date.",
                        ['in_app'],
                        $tenancy,
                    );

                    $applied++;
                }
            }
        }

        return $applied;
    }

    /**
     * The date from which a landlord's rent should be treated as genuinely
     * tracked — anything before it defaults to "opening balance / settled"
     * when unknown, rather than manufactured arrears. Defaults to the start
     * of the current month when the landlord hasn't set one explicitly.
     */
    public function financialStartDate(int $landlordId): Carbon
    {
        $configured = LandlordSetting::get($landlordId, 'financial_start_date');

        return $configured ? Carbon::parse($configured)->startOfDay() : now()->startOfMonth();
    }

    /**
     * Reverse a historical/imported/seeded "paid" entry for a tenant who
     * genuinely didn't pay that period — deletes only payment(s) whose
     * source is a revertible historical assumption. Refuses (returns false)
     * if the charge has any protected-source (genuine) payment on it at all,
     * so a partially-real, partially-assumed charge is never silently
     * touched. Logs the reversal to the `financial_corrections` audit trail
     * before deleting, so the record survives the delete.
     */
    public function revertHistoricalPayment(RentCharge $charge, User $revertedBy): bool
    {
        $payments = $charge->payments;

        $revertible = $payments->filter(fn (Payment $p) => $p->isRevertibleSource());

        if ($revertible->isEmpty()) {
            return false;
        }

        if ($revertible->count() !== $payments->count()) {
            // Mixed charge — at least one protected/genuine payment exists.
            // Never touch it via the historical revert flow.
            return false;
        }

        $charge->loadMissing('tenancy.tenant', 'tenancy.house.block.property');
        $tenancy = $charge->tenancy;

        foreach ($revertible as $payment) {
            activity('financial_corrections')
                ->causedBy($revertedBy)
                ->performedOn($payment)
                ->withProperties([
                    'tenant' => $tenancy->tenant->name,
                    'property' => $tenancy->house->block->property->name,
                    'unit' => $tenancy->house->house_number,
                    'month' => Carbon::create($charge->period_year, $charge->period_month, 1)->format('F Y'),
                    'previous_source' => $payment->source,
                    'previous_status' => $charge->status,
                    'amount' => (string) $payment->amount,
                ])
                ->log('Historical payment reverted');

            $payment->delete();
        }

        return true;
    }

    /**
     * The years a landlord (or, when null, the whole platform) has any
     * tenancy activity in — powers dynamic year selectors instead of
     * hardcoding a range around the current year.
     *
     * @return int[]
     */
    public function availableYears(?int $landlordId): array
    {
        $query = Tenancy::query()
            ->when($landlordId, fn ($q) => $q->whereHas(
                'house.block.property',
                fn ($q2) => $q2->where('landlord_id', $landlordId)
            ));

        $earliest = (clone $query)->min('move_in_date');
        $latestEnd = (clone $query)->max('lease_end_date');

        $startYear = $earliest ? Carbon::parse($earliest)->year : now()->year;
        $endYear = max(now()->year, $latestEnd ? Carbon::parse($latestEnd)->year : now()->year);

        return range($startYear, $endYear);
    }
}
