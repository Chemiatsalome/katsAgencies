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
                    }
                }
            });

        return $created;
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
