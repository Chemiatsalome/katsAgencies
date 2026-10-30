<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\RentCreditAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of rent_credit_allocations rows (Tier 2, design
 * approved 2026-10-25). Every credit-consuming path — ensureChargesForPeriod()'s
 * auto-apply, the reconciliation command, and PaymentObserver's cascade via
 * RentCharge::recalculateStatus() — goes through this service. No other
 * code may write to rent_credit_allocations directly.
 *
 * Concurrency discipline (see lockAffectedCharges()): every multi-charge
 * transaction discovers the complete set of potentially-affected
 * RentCharge ids first (a plain read, no lock), then locks that whole set
 * in one query sorted ascending by id — never source-before-target, never
 * incrementally. Role (source vs target) plays no part in lock order.
 */
class RentCreditAllocationService
{
    /**
     * The FIFO walk over a charge's own confirmed payments (oldest
     * created_at first), returning only the payments that actually
     * contributed to its surplus, each with its own slice. Deterministic
     * convention, not a recovered historical fact, the moment more than
     * one payment has ever contributed — see attributionBasisFor().
     *
     * @return Collection<int, array{payment: Payment, slice: float}>
     */
    public function fifoSlices(RentCharge $charge): Collection
    {
        $running = 0.0;
        $expected = (float) $charge->amount_expected;

        return $charge->payments()->confirmed()->orderBy('created_at')->get()
            ->map(function (Payment $payment) use (&$running, $expected) {
                $before = $running;
                $running = round($running + (float) $payment->amount, 2);
                $slice = $running > $expected ? round($running - max($before, $expected), 2) : 0.0;

                return ['payment' => $payment, 'slice' => $slice];
            })
            ->filter(fn (array $row) => $row['slice'] > 0)
            ->values();
    }

    /**
     * 'exact' only when this charge's surplus has never had more than one
     * contributing payment — the surplus genuinely is that one payment, in
     * full, no ordering convention invoked. The moment a second payment
     * has ever contributed, every row sourced from this charge is
     * 'reconstructed_fifo', including one whose amount happens to fit
     * entirely within what the walk calls "one payment's slice" — because
     * the slice boundary itself is a product of the FIFO convention once a
     * second payment exists. A property of the charge's history, not of
     * any individual draw.
     */
    public function attributionBasisFor(RentCharge $charge): string
    {
        return $this->fifoSlices($charge)->count() <= 1 ? 'exact' : 'reconstructed_fifo';
    }

    private function fifoSliceByPaymentId(RentCharge $charge): Collection
    {
        return $this->fifoSlices($charge)->mapWithKeys(fn (array $row) => [$row['payment']->id => $row['slice']]);
    }

    /** Each contributing payment's fresh FIFO slice minus whatever's already actively attributed to it — i.e. what's left to draw from. */
    private function remainingCapacityByPayment(RentCharge $charge): Collection
    {
        $attributed = RentCreditAllocation::where('source_rent_charge_id', $charge->id)
            ->active()
            ->selectRaw('source_payment_id, sum(amount) as total')
            ->groupBy('source_payment_id')
            ->pluck('total', 'source_payment_id');

        return $this->fifoSlices($charge)->mapWithKeys(fn (array $row) => [
            $row['payment']->id => round($row['slice'] - (float) ($attributed[$row['payment']->id] ?? 0), 2),
        ]);
    }

    /**
     * Discover every RentCharge potentially touched by a change to $charge
     * — itself, every target of its active outgoing allocations (if it's a
     * source), and every source of its active incoming allocations (if
     * it's a target) — then lock that whole, fixed set in one query sorted
     * ascending by id. Discovery always happens before any lock is taken,
     * so the lock set is complete before acquisition rather than growing
     * incrementally (which would reopen deadlock risk).
     *
     * @return Collection<int, RentCharge> keyed by id
     */
    private function lockAffectedCharges(RentCharge $charge): Collection
    {
        $ids = collect([$charge->id])
            ->merge(RentCreditAllocation::where('source_rent_charge_id', $charge->id)->active()->pluck('target_rent_charge_id'))
            ->merge(RentCreditAllocation::where('target_rent_charge_id', $charge->id)->active()->pluck('source_rent_charge_id'))
            ->unique()
            ->sort()
            ->values();

        return RentCharge::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function reverseRow(RentCreditAllocation $allocation, ?int $actorId, string $reason): bool
    {
        return RentCreditAllocation::whereKey($allocation->id)->whereNull('reversed_at')->update([
            'reversed_at' => now(),
            'reversed_by' => $actorId,
            'reversal_reason' => $reason,
        ]) > 0;
    }

    /** Human/UI-initiated reversal of exactly one row. Idempotent — reversing an already-reversed row is a no-op, not an error. */
    public function reverse(RentCreditAllocation $allocation, string $reason, ?int $actorId = null): bool
    {
        return DB::transaction(function () use ($allocation, $reason, $actorId) {
            $this->lockAffectedCharges($allocation->targetRentCharge ?? RentCharge::findOrFail($allocation->target_rent_charge_id));

            return $this->reverseRow($allocation, $actorId, $reason);
        });
    }

    /**
     * Reverse every currently-active row sharing an allocation_group_id —
     * "undo this top-up." Defined as "reverse whatever in this group is
     * still active," not "require all rows were active beforehand," so a
     * group already partially auto-rebalanced doesn't error; it finishes
     * the job. Returns the number of rows actually reversed.
     */
    public function reverseGroup(string $allocationGroupId, string $reason, ?int $actorId = null): int
    {
        $rows = RentCreditAllocation::where('allocation_group_id', $allocationGroupId)->active()->get();
        $reversedCount = 0;

        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $reason, $actorId, &$reversedCount) {
                $this->lockAffectedCharges($row->targetRentCharge ?? RentCharge::findOrFail($row->target_rent_charge_id));

                if ($this->reverseRow($row, $actorId, $reason)) {
                    $reversedCount++;
                }
            });
        }

        return $reversedCount;
    }

    /**
     * Invariant #1 (this charge as a source): recompute the full FIFO walk
     * from only currently-confirmed payments, and for every distinct
     * source_payment_id with active rows against it, compare new slice
     * capacity to attributed amount — reverse only the excess, newest row
     * first. A no-op when this charge has no active outgoing allocations.
     */
    public function reconcileSourceCapacity(RentCharge $charge): void
    {
        $activeBySource = RentCreditAllocation::where('source_rent_charge_id', $charge->id)
            ->active()
            ->get()
            ->groupBy('source_payment_id');

        if ($activeBySource->isEmpty()) {
            return;
        }

        $slices = $this->fifoSliceByPaymentId($charge);

        foreach ($activeBySource as $paymentId => $rows) {
            $capacity = (float) ($slices[$paymentId] ?? 0.0);
            $attributed = round((float) $rows->sum('amount'), 2);
            $excess = round($attributed - $capacity, 2);

            if ($excess <= 0) {
                continue;
            }

            $freed = 0.0;

            foreach ($rows->sortByDesc('created_at') as $row) {
                if ($freed >= $excess) {
                    break;
                }

                $this->reverseRow(
                    $row,
                    null,
                    "Source payment #{$paymentId}'s confirmed surplus no longer supports this allocation — capacity recalculated to KSh ".number_format($capacity, 2).'.'
                );
                $freed += (float) $row->amount;
            }
        }
    }

    /**
     * Invariant #4 (this charge as a target): Σ active incoming allocations
     * must not exceed its current shortfall. A no-op when this charge has
     * no active incoming allocations. Runs unconditionally alongside
     * reconcileSourceCapacity() — a charge's role can change as a side
     * effect of the very event being processed (e.g. a late-fee waiver
     * shifting FIFO boundaries so it becomes a source too), so checking
     * only "whichever role it had before" would miss exactly that case.
     */
    public function reconcileTargetShortfall(RentCharge $charge): void
    {
        $active = RentCreditAllocation::where('target_rent_charge_id', $charge->id)->active()->get();

        if ($active->isEmpty()) {
            return;
        }

        $shortfall = max(0.0, round((float) $charge->amount_expected - (float) $charge->amount_paid, 2));
        $allocated = round((float) $active->sum('amount'), 2);
        $excess = round($allocated - $shortfall, 2);

        if ($excess <= 0) {
            return;
        }

        $freed = 0.0;

        foreach ($active->sortByDesc('created_at') as $row) {
            if ($freed >= $excess) {
                break;
            }

            $this->reverseRow(
                $row,
                null,
                'Target charge\'s shortfall shrank to KSh '.number_format($shortfall, 2).'; previously allocated credit returned.'
            );
            $freed += (float) $row->amount;
        }
    }

    /**
     * The single integration point every amount/status change on a charge
     * must call — RentCharge::recalculateStatus() invokes this immediately
     * after persisting the new amount_paid/status, in the same
     * transaction. Both reconciliation checks run unconditionally.
     */
    public function reconcile(RentCharge $charge): void
    {
        DB::transaction(function () use ($charge) {
            $locked = $this->lockAffectedCharges($charge);
            $fresh = $locked->get($charge->id) ?? $charge;

            $this->reconcileSourceCapacity($fresh);
            $this->reconcileTargetShortfall($fresh);
        });
    }

    /**
     * Automatic-only (no manual "apply credit now" UI, per the approved
     * design). Draws from this tenancy's own 'advance' charges, oldest due
     * date first (matching outstandingChargesWithCredit()'s existing
     * consumption order), to cover as much of $target's shortfall as
     * credit allows. Called from ensureChargesForPeriod() for a
     * newly-created charge, and from the reconciliation command for any
     * already-existing eligible charge.
     *
     * @return Collection<int, RentCreditAllocation> rows created (empty if nothing was eligible)
     */
    public function applyCreditToCharge(RentCharge $target, ?int $actorId = null): Collection
    {
        return DB::transaction(function () use ($target, $actorId) {
            $candidateSourceIds = RentCharge::where('tenancy_id', $target->tenancy_id)
                ->where('status', 'advance')
                ->pluck('id');

            $ids = $candidateSourceIds->push($target->id)->unique()->sort()->values();
            $locked = RentCharge::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $freshTarget = $locked->get($target->id);

            if (! $freshTarget) {
                return collect();
            }

            $alreadyAllocated = (float) RentCreditAllocation::where('target_rent_charge_id', $freshTarget->id)->active()->sum('amount');
            $shortfall = round((float) $freshTarget->amount_expected - (float) $freshTarget->amount_paid - $alreadyAllocated, 2);

            if ($shortfall <= 0) {
                return collect();
            }

            $sources = $locked->except($freshTarget->id)->sortBy('due_date');
            $groupId = (string) Str::uuid();
            $created = collect();

            foreach ($sources as $source) {
                if ($shortfall <= 0) {
                    break;
                }

                $basis = $this->attributionBasisFor($source);

                foreach ($this->remainingCapacityByPayment($source) as $paymentId => $capacity) {
                    if ($shortfall <= 0) {
                        break;
                    }

                    if ($capacity <= 0) {
                        continue;
                    }

                    $draw = round(min($capacity, $shortfall), 2);

                    if ($draw <= 0) {
                        continue;
                    }

                    $created->push(RentCreditAllocation::create([
                        'allocation_group_id' => $groupId,
                        'tenancy_id' => $target->tenancy_id,
                        'source_payment_id' => $paymentId,
                        'source_rent_charge_id' => $source->id,
                        'target_rent_charge_id' => $freshTarget->id,
                        'amount' => $draw,
                        'attribution_basis' => $basis,
                        'created_by' => $actorId,
                    ]));

                    $shortfall = round($shortfall - $draw, 2);
                }
            }

            return $created;
        });
    }

    /**
     * How much of this tenancy's surplus genuinely remains unspent — the
     * raw surplus (Tenancy::advanceCreditAvailable()) minus whatever's
     * already been formally allocated elsewhere via rent_credit_allocations.
     * applyLateFees() must read credit through this, not
     * advanceCreditAvailable() directly: the raw figure never shrinks as
     * allocations are created, so using it as-is would let the same
     * credit net out a late fee here *and* already be spent covering a
     * different charge's shortfall — exactly the double-counting Tier 2
     * exists to prevent.
     */
    public function unallocatedCredit(\App\Models\Tenancy $tenancy): float
    {
        $surplus = (float) $tenancy->advanceCreditAvailable();

        if ($surplus <= 0) {
            return 0.0;
        }

        $advanceChargeIds = RentCharge::where('tenancy_id', $tenancy->id)->where('status', 'advance')->pluck('id');
        $allocated = (float) RentCreditAllocation::whereIn('source_rent_charge_id', $advanceChargeIds)->active()->sum('amount');

        return round($surplus - $allocated, 2);
    }

    /**
     * Standing assertion #1 (reconciliation command's actual job to fix):
     * every outstanding charge, across every tenancy, that has eligible
     * unallocated credit sitting on another charge of the same tenancy but
     * not yet drawn against it.
     *
     * @return Collection<int, RentCharge>
     */
    public function chargesEligibleForMissingAllocation(): Collection
    {
        $tenancyIdsWithUnallocatedCredit = RentCharge::where('status', 'advance')->get()
            ->groupBy('tenancy_id')
            ->filter(function (Collection $charges) {
                $surplus = (float) $charges->sum(fn (RentCharge $c) => (float) $c->amount_paid - (float) $c->amount_expected);
                $allocated = (float) RentCreditAllocation::whereIn('source_rent_charge_id', $charges->pluck('id'))->active()->sum('amount');

                return round($surplus - $allocated, 2) > 0;
            })
            ->keys();

        return RentCharge::outstanding()
            ->whereIn('tenancy_id', $tenancyIdsWithUnallocatedCredit)
            ->get()
            ->filter(function (RentCharge $charge) {
                $allocated = (float) RentCreditAllocation::where('target_rent_charge_id', $charge->id)->active()->sum('amount');
                $shortfall = (float) $charge->amount_expected - (float) $charge->amount_paid;

                return round($shortfall - $allocated, 2) > 0;
            })
            ->values();
    }

    /**
     * Standing assertion #2 — a defensive audit, not expected to ever find
     * anything under correct locking: any 'advance' charge whose active
     * outgoing allocations sum to more than its own surplus.
     *
     * @return Collection<int, array{charge: RentCharge, surplus: float, allocated: float}>
     */
    public function auditOverspentSources(): Collection
    {
        return RentCharge::where('status', 'advance')->get()
            ->map(function (RentCharge $charge) {
                $surplus = (float) $charge->amount_paid - (float) $charge->amount_expected;
                $allocated = (float) RentCreditAllocation::where('source_rent_charge_id', $charge->id)->active()->sum('amount');

                return ['charge' => $charge, 'surplus' => $surplus, 'allocated' => $allocated];
            })
            ->filter(fn (array $row) => round($row['allocated'] - $row['surplus'], 2) > 0)
            ->values();
    }

    /**
     * Standing assertion #3 — any charge whose active incoming allocations
     * sum to more than its own gross shortfall.
     *
     * @return Collection<int, array{charge: RentCharge, shortfall: float, allocated: float}>
     */
    public function auditExcessiveTargets(): Collection
    {
        $targetIds = RentCreditAllocation::active()->distinct()->pluck('target_rent_charge_id');

        return RentCharge::whereIn('id', $targetIds)->get()
            ->map(function (RentCharge $charge) {
                $shortfall = max(0.0, (float) $charge->amount_expected - (float) $charge->amount_paid);
                $allocated = (float) RentCreditAllocation::where('target_rent_charge_id', $charge->id)->active()->sum('amount');

                return ['charge' => $charge, 'shortfall' => $shortfall, 'allocated' => $allocated];
            })
            ->filter(fn (array $row) => round($row['allocated'] - $row['shortfall'], 2) > 0)
            ->values();
    }

    /**
     * The corrected reconciliation invariant (proven algebraically): for
     * every tenancy, Tenancy::balance() computed the old, derived-only way
     * must equal G′ − AllocatedToOutstanding − UnallocatedCredit computed
     * through rent_credit_allocations, to the cent.
     *
     * @return Collection<int, array{tenancy_id: int, old: float, new: float, diff: float}>
     */
    public function reconciliationInvariantMismatches(): Collection
    {
        $tenancyIds = RentCharge::query()
            ->where(fn ($q) => $q->where('status', 'advance')->orWhere(
                fn ($q2) => $q2->whereIn('status', ['unpaid', 'partial', 'overdue'])->where('due_date', '<=', now())
            ))
            ->distinct()
            ->pluck('tenancy_id');

        return $tenancyIds->map(function (int $tenancyId) {
            $tenancy = \App\Models\Tenancy::find($tenancyId);

            if (! $tenancy) {
                return null;
            }

            $old = $tenancy->balance();

            $gPrime = (float) $tenancy->rentCharges()->outstanding()->get()
                ->sum(fn (RentCharge $c) => (float) $c->amount_expected - (float) $c->amount_paid);

            $outstandingIds = $tenancy->rentCharges()->outstanding()->pluck('id');
            $allocatedToOutstanding = (float) RentCreditAllocation::whereIn('target_rent_charge_id', $outstandingIds)->active()->sum('amount');

            $advanceCharges = $tenancy->rentCharges()->where('status', 'advance')->get();
            $surplus = (float) $advanceCharges->sum(fn (RentCharge $c) => (float) $c->amount_paid - (float) $c->amount_expected);
            $advanceIds = $advanceCharges->pluck('id');
            $allocatedFromAdvance = (float) RentCreditAllocation::whereIn('source_rent_charge_id', $advanceIds)->active()->sum('amount');
            $unallocatedCredit = $surplus - $allocatedFromAdvance;

            $new = round($gPrime - $allocatedToOutstanding - $unallocatedCredit, 2);
            $old = round((float) $old, 2);

            return [
                'tenancy_id' => $tenancyId,
                'old' => $old,
                'new' => $new,
                'diff' => round($new - $old, 2),
            ];
        })
            ->filter()
            ->filter(fn (array $row) => $row['diff'] !== 0.0)
            ->values();
    }
}
