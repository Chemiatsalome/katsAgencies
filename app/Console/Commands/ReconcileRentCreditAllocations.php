<?php

namespace App\Console\Commands;

use App\Models\RentCreditAllocation;
use App\Services\RentCreditAllocationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Tier 2 (design approved 2026-10-25). Default (no flags) is a read-only
 * dry run: reports the corrected reconciliation invariant across every
 * tenancy, the two defensive audits, and how many existing charges have
 * eligible unapplied credit sitting unused. --apply actually creates those
 * missing allocations (idempotent — safe to re-run; a charge already fully
 * allocated is simply skipped). --rollback reverses every active
 * allocation with a system reason, restoring pure-derivation (Tier 1/1b)
 * behaviour exactly, for use before a cutover is reverted.
 */
#[Signature('rent-credit:reconcile {--apply} {--rollback}')]
#[Description('Tier 2 rent-credit allocation reconciliation — dry run by default; --apply creates missing allocations for already-existing eligible charges; --rollback reverses every active allocation.')]
class ReconcileRentCreditAllocations extends Command
{
    public function handle(RentCreditAllocationService $service): int
    {
        if ($this->option('rollback')) {
            return $this->rollback($service);
        }

        $mismatches = $service->reconciliationInvariantMismatches();
        $overspentSources = $service->auditOverspentSources();
        $excessiveTargets = $service->auditExcessiveTargets();
        $eligible = $service->chargesEligibleForMissingAllocation();

        $this->info('Reconciliation invariant (balance() old-method vs allocation-table new-method): '
            .($mismatches->isEmpty() ? 'zero mismatches.' : "{$mismatches->count()} MISMATCH(ES)."));

        foreach ($mismatches as $row) {
            $this->error("  Tenancy #{$row['tenancy_id']}: old={$row['old']} new={$row['new']} diff={$row['diff']}");
        }

        $this->info('Overspent sources (allocated more than their own surplus): '.$overspentSources->count());
        foreach ($overspentSources as $row) {
            $this->error("  RentCharge #{$row['charge']->id}: surplus={$row['surplus']} allocated={$row['allocated']}");
        }

        $this->info('Excessive targets (allocated more than their own shortfall): '.$excessiveTargets->count());
        foreach ($excessiveTargets as $row) {
            $this->error("  RentCharge #{$row['charge']->id}: shortfall={$row['shortfall']} allocated={$row['allocated']}");
        }

        $this->info("Charges with eligible unapplied credit sitting unused: {$eligible->count()}");
        foreach ($eligible as $charge) {
            $this->line("  RentCharge #{$charge->id} (tenancy #{$charge->tenancy_id}, period {$charge->period_year}-{$charge->period_month})");
        }

        if (! $this->option('apply')) {
            $this->comment($eligible->isEmpty() && $mismatches->isEmpty() && $overspentSources->isEmpty() && $excessiveTargets->isEmpty()
                ? 'Dry run clean — nothing to apply.'
                : 'Dry run only — re-run with --apply to create the missing allocations above. Any mismatch/audit finding above must be investigated first; --apply does not fix those.');

            return ($mismatches->isEmpty() && $overspentSources->isEmpty() && $excessiveTargets->isEmpty()) ? self::SUCCESS : self::FAILURE;
        }

        if ($mismatches->isNotEmpty() || $overspentSources->isNotEmpty() || $excessiveTargets->isNotEmpty()) {
            $this->error('Refusing to --apply while the invariant or audits above show a problem — fix the underlying issue first.');

            return self::FAILURE;
        }

        $created = 0;

        foreach ($eligible as $charge) {
            $created += $service->applyCreditToCharge($charge->fresh())->count();
        }

        $this->info("Created {$created} allocation row(s).");

        $after = $service->reconciliationInvariantMismatches();
        $this->info('Post-apply invariant check: '.($after->isEmpty() ? 'zero mismatches.' : "{$after->count()} MISMATCH(ES) — investigate immediately."));

        return $after->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function rollback(RentCreditAllocationService $service): int
    {
        $rows = RentCreditAllocation::active()->get();
        $this->info("Reversing {$rows->count()} active allocation(s)...");

        foreach ($rows as $row) {
            $service->reverse($row, 'Tier 2 rollback.');
        }

        $remaining = RentCreditAllocation::active()->count();
        $this->info("Done. {$remaining} still active (expect 0).");

        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }
}
