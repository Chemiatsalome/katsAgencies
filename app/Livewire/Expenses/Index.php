<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\DepositService;
use App\Services\MaintenanceCostService;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use RuntimeException;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $categoryId = 'all';

    #[Url]
    public string $month = '';

    #[Url]
    public int $perPage = 25;

    public ?int $deductingExpenseId = null;

    public string $deduction_amount = '';

    public string $deduction_reason = '';

    public string $deduction_date = '';

    public $deduction_evidence;

    // --- Liability decision modal state (Tier 2) ---------------------------------------
    public ?int $decidingExpenseId = null;

    public ?int $liability_tenancy_id = null;

    public string $liability_reason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Expense::class);
    }

    public function delete(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        $this->authorize('delete', $expense);

        if ($expense->deposit_deduction_id) {
            session()->flash('error', 'This expense funded a deposit deduction and can\'t be deleted. Void the deduction instead if the tenant disputed it.');

            return;
        }

        $expense->delete();
    }

    /**
     * Occupancy is a candidate signal only — never proof of liability
     * (§7/§8, design approved 2026-10-25). Checks expense->tenancy_id
     * first — reliably inherited from the maintenance task that generated
     * it (MaintenanceCostService::recordExpenseFor()), never guessed; NULL
     * for a landlord's direct manual entry, which has no tenancy context
     * at all. Falls back to exact-date occupancy of the expense's own
     * house on its own expense_date, not "whoever lives there now".
     * Returns 'none' (no reliable date, or a vacant gap), 'multiple'
     * (overlapping tenancy records — bad data, never guessed), or
     * 'single' + the one tenancy.
     *
     * @return array{kind: string, tenancy: ?Tenancy}
     */
    public function candidateTenancyFor(Expense $expense): array
    {
        if ($expense->tenancy_id) {
            $tenancy = Tenancy::find($expense->tenancy_id);

            if ($tenancy) {
                return ['kind' => 'single', 'tenancy' => $tenancy];
            }
        }

        if (! $expense->house_id || ! $expense->expense_date) {
            return ['kind' => 'none', 'tenancy' => null];
        }

        $matches = Tenancy::occupyingOn($expense->house_id, $expense->expense_date)->get();

        return match ($matches->count()) {
            0 => ['kind' => 'none', 'tenancy' => null],
            1 => ['kind' => 'single', 'tenancy' => $matches->first()],
            default => ['kind' => 'multiple', 'tenancy' => null],
        };
    }

    /** Every tenancy that has ever occupied this expense's house — the full override list for the liability decision form. */
    public function everyTenancyFor(Expense $expense)
    {
        return $expense->house_id
            ? Tenancy::where('house_id', $expense->house_id)->with('tenant')->orderByDesc('move_in_date')->get()
            : collect();
    }

    /**
     * Deduction is eligible only once an active, explicit tenant_liable
     * decision exists — never inferred from occupancy or a maintenance
     * task's complaint alone. A landlord_responsible decision (if any) is
     * itself an active decision of the *other* type, so this already
     * excludes it without a separate check. A task-derived expense still
     * defers to the task's own confirmation rule (tenant must have
     * confirmed the fix) on top of this.
     */
    public function canDeductFromDeposit(Expense $expense): bool
    {
        if ($expense->deposit_deduction_id) {
            return false;
        }

        $decision = app(TenantLiabilityDecisionService::class)->activeDecisionFor($expense);

        if (! $decision || ! $decision->isTenantLiable()) {
            return false;
        }

        if ($expense->maintenanceTask && ! app(MaintenanceCostService::class)->canDeductFromDeposit($expense->maintenanceTask)) {
            return false;
        }

        return $decision->tenancy && $decision->tenancy->depositHeldBalance() > 0;
    }

    // --- Liability decision -------------------------------------------------------------

    public function openLiabilityDecision(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        $this->authorize('update', $expense);

        $this->decidingExpenseId = $expenseId;
        $candidate = $this->candidateTenancyFor($expense);
        $this->liability_tenancy_id = $candidate['tenancy']?->id;
        $this->liability_reason = '';
    }

    public function cancelLiabilityDecision(): void
    {
        $this->reset(['decidingExpenseId', 'liability_tenancy_id', 'liability_reason']);
    }

    public function confirmTenantLiability(): void
    {
        $expense = Expense::findOrFail($this->decidingExpenseId);
        $this->authorize('update', $expense);

        $this->validate([
            'liability_tenancy_id' => ['required', 'integer', 'exists:tenancies,id'],
            'liability_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            app(TenantLiabilityDecisionService::class)->decide(
                $expense, 'tenant_liable', (int) $this->liability_tenancy_id, $this->liability_reason, Auth::id(),
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->addError('liability_reason', $e->getMessage());

            return;
        }

        $this->cancelLiabilityDecision();
        session()->flash('status', 'Liability decision recorded.');
    }

    public function markLandlordResponsible(): void
    {
        $expense = Expense::findOrFail($this->decidingExpenseId);
        $this->authorize('update', $expense);

        $this->validate(['liability_reason' => ['required', 'string', 'max:1000']]);

        try {
            app(TenantLiabilityDecisionService::class)->decide(
                $expense, 'landlord_responsible', null, $this->liability_reason, Auth::id(),
            );
        } catch (RuntimeException $e) {
            $this->addError('liability_reason', $e->getMessage());

            return;
        }

        $this->cancelLiabilityDecision();
        session()->flash('status', 'Marked as a landlord responsibility — this can never be deducted from a tenant\'s deposit while this decision stands.');
    }

    // --- Deposit deduction (now gated by the decision above) ---------------------------

    public function startDeduction(int $expenseId): void
    {
        $expense = Expense::with('maintenanceTask.complaint')->findOrFail($expenseId);
        $this->authorize('update', $expense);

        if (! $this->canDeductFromDeposit($expense)) {
            session()->flash('error', 'This expense isn\'t eligible for a deposit deduction right now.');

            return;
        }

        $this->deductingExpenseId = $expenseId;
        $this->deduction_amount = (string) $expense->amount;
        $this->deduction_reason = $expense->description ?: $expense->category->name;
        $this->deduction_date = $expense->expense_date->format('Y-m-d');
        $this->deduction_evidence = null;
    }

    public function cancelDeduction(): void
    {
        $this->reset(['deductingExpenseId', 'deduction_amount', 'deduction_reason', 'deduction_date', 'deduction_evidence']);
    }

    public function confirmDeduction(): void
    {
        $expense = Expense::with('maintenanceTask.complaint')->findOrFail($this->deductingExpenseId);
        $this->authorize('update', $expense);

        if (! $this->canDeductFromDeposit($expense)) {
            session()->flash('error', 'This expense isn\'t eligible for a deposit deduction right now.');
            $this->cancelDeduction();

            return;
        }

        $this->validate([
            'deduction_amount' => ['required', 'numeric', 'min:0.01'],
            'deduction_reason' => ['required', 'string', 'max:1000'],
            'deduction_date' => ['required', 'date'],
            'deduction_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $decision = app(TenantLiabilityDecisionService::class)->activeDecisionFor($expense);
        $tenancy = $decision?->tenancy;

        // Never fall back to the current occupant — if the decided tenancy
        // (or its deposit) is no longer available, this refuses outright.
        if (! $tenancy || $tenancy->depositHeldBalance() <= 0) {
            $this->addError('deduction_amount', 'This tenant\'s deposit has already been settled or is unavailable — this cost can\'t be deducted from a deposit. Bill them directly, or mark this expense as absorbed.');

            return;
        }

        try {
            $deduction = app(DepositService::class)->recordDeduction(
                $tenancy,
                (float) $this->deduction_amount,
                $this->deduction_reason,
                $this->deduction_date,
                Auth::id(),
                $this->deduction_evidence ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('deduction_amount', $e->getMessage());

            return;
        }

        $expense->update(['deposit_deduction_id' => $deduction->id]);
        $expense->maintenanceTask?->update(['deposit_deduction_id' => $deduction->id]);

        $this->cancelDeduction();
        session()->flash('status', 'Deducted from the tenant\'s security deposit.');
    }

    public function render()
    {
        $landlordId = Auth::id();

        $query = Expense::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['property', 'category', 'maintenanceTask.complaint', 'depositDeduction']);

        if ($this->propertyId !== 'all') {
            $query->where('property_id', (int) $this->propertyId);
        }

        if ($this->categoryId !== 'all') {
            $query->where('expense_category_id', (int) $this->categoryId);
        }

        if ($this->month) {
            $query->whereRaw("DATE_FORMAT(expense_date, '%Y-%m') = ?", [$this->month]);
        }

        $total = (clone $query)->sum('amount');
        $expenses = $query->orderByDesc('expense_date')->paginate($this->perPage);

        $liabilityService = app(TenantLiabilityDecisionService::class);
        $decisionsByExpenseId = $expenses->getCollection()->mapWithKeys(
            fn (Expense $e) => [$e->id => $liabilityService->activeDecisionFor($e)]
        );

        $decidingExpense = $this->decidingExpenseId ? Expense::find($this->decidingExpenseId) : null;
        $deductingExpense = $this->deductingExpenseId ? Expense::find($this->deductingExpenseId) : null;
        $deductingTenancy = $deductingExpense ? $liabilityService->activeDecisionFor($deductingExpense)?->tenancy : null;

        return view('livewire.expenses.index', [
            'expenses' => $expenses,
            'total' => $total,
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
            'decisionsByExpenseId' => $decisionsByExpenseId,
            'decidingExpense' => $decidingExpense,
            'decidingCandidate' => $decidingExpense ? $this->candidateTenancyFor($decidingExpense) : null,
            'decidingTenancies' => $decidingExpense ? $this->everyTenancyFor($decidingExpense) : collect(),
            'deductingExpense' => $deductingExpense,
            'deductingTenancy' => $deductingTenancy,
        ]);
    }
}
