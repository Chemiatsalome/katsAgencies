<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits one lump-sum payment across whatever is actually outstanding on a
 * tenancy right now, oldest period first — a single payment (an M-Pesa STK
 * push or a tenant's own offline-payment claim) isn't pinned to one month,
 * so this is shared by both MpesaCallbackController and Tenant\PayRent's
 * offline-submission flow rather than duplicated. Anything left over after
 * every outstanding charge is covered is applied as an advance against the
 * most recent charge.
 */
class PaymentAllocationService
{
    /**
     * @param  array  $attributes  Every other Payment column (method, source, status, reference_number, recorded_by, collection_mode, notes, ...) merged into each row created.
     * @return Collection<int, Payment>
     */
    public function allocate(Tenancy $tenancy, float $amount, Carbon $date, array $attributes): Collection
    {
        // Tier 2 (design approved 2026-10-25), §6: a mid-loop failure
        // across however many charges this touches must roll back every
        // Payment::create() from this one call, not leave a partial split
        // — the caller sees either "fully applied" or "nothing happened."
        return DB::transaction(fn () => $this->allocateWithinTransaction($tenancy, $amount, $date, $attributes));
    }

    /**
     * @param  array  $attributes  Every other Payment column (method, source, status, reference_number, recorded_by, collection_mode, notes, ...) merged into each row created.
     * @return Collection<int, Payment>
     */
    private function allocateWithinTransaction(Tenancy $tenancy, float $amount, Carbon $date, array $attributes): Collection
    {
        $remaining = $amount;
        $payments = collect();
        $notes = $attributes['notes'] ?? null;

        $outstanding = $tenancy->rentCharges()->outstanding()->orderBy('due_date')->get();

        foreach ($outstanding as $charge) {
            if ($remaining <= 0) {
                break;
            }

            $due = (float) $charge->amount_expected - (float) $charge->amount_paid;
            $portion = min($due, $remaining);

            $payments->push(Payment::create(array_merge($attributes, [
                'tenancy_id' => $tenancy->id,
                'rent_charge_id' => $charge->id,
                'amount' => $portion,
                'payment_date' => $date->toDateString(),
            ])));

            $remaining -= $portion;
        }

        if ($remaining > 0) {
            $targetChargeId = $outstanding->last()?->id
                ?? $tenancy->rentCharges()->orderByDesc('period_year')->orderByDesc('period_month')->value('id');

            if (! $targetChargeId) {
                // No RentCharge exists for this tenancy at all yet — e.g. a
                // payment arriving before the nightly rent:generate-charges
                // cron, or any page that calls ensureChargesForPeriod(), has
                // ever run for it. A payment must never end up with a null
                // rent_charge_id: RentCharge::recalculateStatus() only sums
                // payments actually linked to a charge, so an unlinked
                // payment would stay confirmed and genuine for collection
                // totals, yet silently never show up in the tenant's
                // balance or the Rent Charges table even once confirmed.
                app(RentScheduleService::class)->ensureChargesForPeriod(
                    null, $tenancy->house->block->property_id, $date->year, $date->month
                );
                $targetChargeId = $tenancy->rentCharges()->orderByDesc('period_year')->orderByDesc('period_month')->value('id');
            }

            $payments->push(Payment::create(array_merge($attributes, [
                'tenancy_id' => $tenancy->id,
                'rent_charge_id' => $targetChargeId,
                'amount' => $remaining,
                'payment_date' => $date->toDateString(),
                'notes' => $notes ? "{$notes}, advance payment" : 'Advance payment',
            ])));
        }

        return $payments;
    }
}
