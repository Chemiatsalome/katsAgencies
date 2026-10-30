<?php

namespace App\Services;

use App\Models\DepositDeduction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class MaintenanceCostService
{
    /**
     * Record a maintenance task's actual cost as a property expense — idempotent,
     * so marking a task "completed" twice never creates a duplicate expense.
     */
    public function recordExpenseFor(MaintenanceTask $task): ?Expense
    {
        if ($task->expense_id || ! $task->actual_cost) {
            return $task->expense;
        }

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);

        $expense = Expense::create([
            'property_id' => $task->property_id,
            'block_id' => $task->block_id,
            'house_id' => $task->house_id,
            // §8: inherited from the task, never re-derived.
            'tenancy_id' => $task->tenancy_id,
            'expense_category_id' => $category->id,
            'vendor' => $task->vendor?->name,
            'amount' => $task->actual_cost,
            'expense_date' => $task->completed_at?->toDateString() ?? now()->toDateString(),
            'description' => $task->title,
        ]);

        $task->update(['expense_id' => $expense->id]);

        return $expense;
    }

    /**
     * A second (or third...) cost against a task that's already had its
     * first expense recorded — e.g. the tenant reopened it because the
     * first fix didn't hold, and the follow-up visit cost more. Unlike
     * recordExpenseFor(), this is deliberately NOT idempotent: each call is
     * a genuinely separate expense, since MaintenanceTask only has a single
     * expense_id slot to point back at the original. Both rows still show
     * up against the same property/house for reporting; only the first is
     * linked back from the task row itself.
     */
    public function recordAdditionalExpenseFor(MaintenanceTask $task, float $amount, ?string $note = null): Expense
    {
        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);

        return Expense::create([
            'property_id' => $task->property_id,
            'block_id' => $task->block_id,
            'house_id' => $task->house_id,
            'tenancy_id' => $task->tenancy_id,
            'expense_category_id' => $category->id,
            'vendor' => $task->vendor?->name,
            'amount' => $amount,
            'expense_date' => now()->toDateString(),
            'description' => "{$task->title}, additional cost".($note ? ": {$note}" : ''),
        ]);
    }

    /**
     * §8/Tier 2 (design approved 2026-10-25): a candidate signal only,
     * never proof of liability — renamed from responsibleTenancyFor() to
     * make that explicit. Even an occurrence/association fact (below)
     * never on its own establishes who's liable for the resulting cost —
     * see TenantLiabilityDecisionService (§7).
     *
     * Resolution order:
     * 1. task->tenancy_id (or its complaint's), if set — reliably
     *    captured at creation (Tenant\Maintenance\Create::submit(),
     *    inherited by Maintenance\Index::convertToTask()), never guessed.
     * 2. The complaint's own tenant (if any) is a stronger suggestion than
     *    bare occupancy, since they're the one who reported it — but
     *    filing a complaint still only proves they occupied/reported, not
     *    that they caused the damage. Bounded to the complaint's own
     *    created_at date, not "whichever of this tenant's tenancies on
     *    this house is most recent" — a tenant who's had two separate
     *    stays in the same unit would otherwise silently resolve to the
     *    wrong one if a complaint predates their most recent stay.
     * 3. Exact-date occupancy (Tenancy::occupyingOn()) using completed_at
     *    (or scheduled_date, if not yet completed) when there's no
     *    complaint to anchor to at all.
     *
     * @return array{kind: string, tenancy: ?Tenancy}
     */
    public function candidateTenancyFor(MaintenanceTask $task): array
    {
        $capturedTenancyId = $task->tenancy_id ?? $task->complaint?->tenancy_id;

        if ($capturedTenancyId) {
            $tenancy = Tenancy::find($capturedTenancyId);

            if ($tenancy) {
                return ['kind' => 'single', 'tenancy' => $tenancy];
            }
        }

        if ($task->complaint?->tenant_id) {
            $matches = Tenancy::where('house_id', $task->house_id)
                ->where('tenant_id', $task->complaint->tenant_id)
                ->where('move_in_date', '<=', $task->complaint->created_at)
                ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $task->complaint->created_at))
                ->get();

            if ($matches->count() === 1) {
                return ['kind' => 'single', 'tenancy' => $matches->first()];
            }

            if ($matches->count() > 1) {
                return ['kind' => 'multiple', 'tenancy' => null];
            }
        }

        if (! $task->house_id) {
            return ['kind' => 'none', 'tenancy' => null];
        }

        $date = $task->completed_at ?? $task->scheduled_date;

        if (! $date) {
            return ['kind' => 'none', 'tenancy' => null];
        }

        $matches = Tenancy::occupyingOn($task->house_id, $date)->get();

        return match ($matches->count()) {
            0 => ['kind' => 'none', 'tenancy' => null],
            1 => ['kind' => 'single', 'tenancy' => $matches->first()],
            default => ['kind' => 'multiple', 'tenancy' => null],
        };
    }

    /**
     * Whether this task's cost is even eligible to be deducted yet. A task
     * with no linked complaint (the landlord logged it directly, not in
     * response to anything a tenant reported) has no confirmation step at
     * all, so it's eligible as soon as it's costed — but it still needs an
     * explicit, active tenant_liable decision (Tier 2) before anything can
     * actually be deducted; filing/not-filing a complaint was never proof
     * of liability on its own. A task that DID come from a complaint is
     * only eligible once the tenant has confirmed the fix actually
     * resolved it — charging them before they've even agreed the work was
     * done is exactly the kind of thing that produces disputes.
     * "Reopened" (confirmation reset back to null because the tenant said
     * it wasn't actually fixed) correctly blocks this again.
     */
    public function canDeductFromDeposit(MaintenanceTask $task): bool
    {
        if ($task->deposit_deduction_id) {
            return false;
        }

        if (! $task->actual_cost || (float) $task->actual_cost <= 0) {
            return false;
        }

        if ($task->complaint_id && ! $task->complaint->tenant_confirmed_at) {
            return false;
        }

        $decision = app(TenantLiabilityDecisionService::class)->activeDecisionFor($task);

        return $decision && $decision->isTenantLiable() && $decision->tenancy && $decision->tenancy->depositHeldBalance() > 0;
    }

    /**
     * Deduct this task's already-recorded cost from the tenancy named by
     * its active tenant_liable decision — never auto-resolved, and never
     * substituted for the current occupant if that tenancy's deposit is
     * unavailable (Tier 2). A deliberate, separate decision the landlord
     * makes, never automatic. Refuses to run twice for the same task,
     * before a cost exists, before the tenant has confirmed the fix when
     * this task came from their own complaint, or before a liability
     * decision exists at all (see canDeductFromDeposit()).
     */
    public function deductFromTenantDeposit(MaintenanceTask $task, int $recordedBy, ?UploadedFile $evidence = null): DepositDeduction
    {
        if ($task->deposit_deduction_id) {
            throw new RuntimeException('This task\'s cost has already been deducted from the tenant\'s deposit.');
        }

        if (! $task->actual_cost || (float) $task->actual_cost <= 0) {
            throw new RuntimeException('This task has no recorded cost yet.');
        }

        if ($task->complaint_id && ! $task->complaint->tenant_confirmed_at) {
            throw new RuntimeException('The tenant hasn\'t confirmed this fix yet — wait for their confirmation before deducting the cost.');
        }

        $decision = app(TenantLiabilityDecisionService::class)->activeDecisionFor($task);

        if (! $decision || ! $decision->isTenantLiable()) {
            throw new RuntimeException('No tenant-liability decision has been recorded for this task yet — decide who\'s liable before deducting.');
        }

        $tenancy = $decision->tenancy;

        if (! $tenancy || $tenancy->depositHeldBalance() <= 0) {
            throw new RuntimeException('That tenant\'s deposit has already been settled or is unavailable — this can\'t be deducted from a deposit. Bill them directly, or mark this expense as absorbed.');
        }

        $deduction = app(DepositService::class)->recordDeduction(
            $tenancy,
            (float) $task->actual_cost,
            "Maintenance: {$task->title}",
            $task->completed_at?->toDateString() ?? now()->toDateString(),
            $recordedBy,
            $evidence,
        );

        $task->update(['deposit_deduction_id' => $deduction->id]);
        $task->expense?->update(['deposit_deduction_id' => $deduction->id]);

        return $deduction;
    }
}
