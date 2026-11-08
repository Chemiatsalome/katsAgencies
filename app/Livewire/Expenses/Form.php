<?php

namespace App\Livewire\Expenses;

use App\Models\Block;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\Property;
use App\Models\Tenancy;
use App\Services\DepositService;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.admin')]
class Form extends Component
{
    use WithFileUploads;

    public ?Expense $expense = null;

    public ?int $property_id = null;

    public ?int $block_id = null;

    public ?int $house_id = null;

    public ?int $expense_category_id = null;

    public string $vendor = '';

    public string $amount = '';

    public string $expense_date;

    public string $description = '';

    // Direct maintenance/expense deposit charging — only ever offered when
    // creating a brand-new expense, never when editing an existing one
    // (which already has its own Decide Liability / Deduct from Deposit
    // actions on the Expenses list).
    public string $cost_responsibility = 'landlord';

    public ?int $liability_tenancy_id = null;

    public string $deduction_amount = '';

    public string $deduction_reason = '';

    public $deduction_evidence;

    public function mount(?Expense $expense = null): void
    {
        if ($expense?->exists) {
            $this->authorize('update', $expense);
            $this->expense = $expense;
            $this->property_id = $expense->property_id;
            $this->block_id = $expense->block_id;
            $this->house_id = $expense->house_id;
            $this->expense_category_id = $expense->expense_category_id;
            $this->vendor = (string) $expense->vendor;
            $this->amount = (string) $expense->amount;
            $this->expense_date = $expense->expense_date->format('Y-m-d');
            $this->description = (string) $expense->description;
        } else {
            $this->authorize('create', Expense::class);
            $this->expense_date = now()->format('Y-m-d');

            if (request()->query('property_id')) {
                $property = Property::find((int) request()->query('property_id'));
                if ($property) {
                    $this->property_id = $property->id;
                }
            }
        }
    }

    public function updatedPropertyId(): void
    {
        $this->block_id = null;
        $this->house_id = null;
        $this->liability_tenancy_id = null;
    }

    public function updatedBlockId(): void
    {
        $this->house_id = null;
        $this->liability_tenancy_id = null;
    }

    public function updatedHouseId(): void
    {
        $this->liability_tenancy_id = null;
    }

    public function updatedAmount(): void
    {
        $this->deduction_amount = $this->amount;
    }

    /** Every tenancy that has ever occupied this house — mirrors Maintenance\Index::everyTenancyForTask(). */
    private function candidateTenancies(): \Illuminate\Support\Collection
    {
        return $this->house_id
            ? Tenancy::where('house_id', $this->house_id)->with('tenant')->orderByDesc('move_in_date')->get()
            : collect();
    }

    public function save(): void
    {
        $rules = [
            'property_id' => ['required', 'exists:properties,id'],
            'block_id' => ['nullable', 'exists:blocks,id'],
            'house_id' => ['nullable', 'exists:houses,id'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ];

        // Only ever offered on a brand-new expense — see the property docblock.
        $chargingTenant = ! $this->expense && $this->cost_responsibility === 'tenant';

        if ($chargingTenant) {
            // A tenancy only has meaning tied to a specific unit — charging
            // the whole-property/whole-block case (no house_id) has no
            // single tenancy to scope to, so it's required here even
            // though it's merely optional for a plain landlord expense.
            $rules += [
                'house_id' => ['required', 'exists:houses,id'],
                'liability_tenancy_id' => ['required', 'integer', 'exists:tenancies,id'],
                'deduction_amount' => ['required', 'numeric', 'min:0.01'],
                'deduction_reason' => ['required', 'string', 'max:1000'],
                'deduction_evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            ];
        }

        $data = $this->validate($rules);

        if ($chargingTenant && (float) $data['deduction_amount'] > (float) $data['amount'] + 0.01) {
            $this->addError('deduction_amount', 'The deduction amount cannot exceed the expense amount.');

            return;
        }

        $property = Property::findOrFail($data['property_id']);
        $this->authorize('update', $property);

        $expenseFields = [
            'property_id' => $data['property_id'],
            'block_id' => $data['block_id'] ?: null,
            'house_id' => $data['house_id'] ?: null,
            'expense_category_id' => $data['expense_category_id'],
            'vendor' => $data['vendor'] ?: null,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'] ?: null,
        ];

        if ($this->expense) {
            $this->expense->update($expenseFields);
            session()->flash('status', 'Expense updated.');
            $this->redirectRoute('manage.expenses.index', navigate: true);

            return;
        }

        // Do not allow ordinary expense-recording authority to authorize a
        // deposit deduction — a separate, explicit check, same boundary as
        // Maintenance\Index's manage_liability requirement.
        if ($chargingTenant) {
            abort_unless(
                Auth::user()->hasRole('landlord') || Auth::user()->hasManagerPermission('maintenance.manage_liability'),
                403,
            );
        }

        $expenseFields['recorded_by'] = Auth::id();

        if ($chargingTenant) {
            // An occurrence/association fact, same as how MaintenanceCostService
            // ::recordExpenseFor() copies it forward from a task — the landlord
            // explicitly identified this tenancy, unlike a plain manual entry.
            $expenseFields['tenancy_id'] = (int) $data['liability_tenancy_id'];
        }

        $expense = Expense::create($expenseFields);

        if ($chargingTenant) {
            // The expense itself is always recorded regardless of what
            // happens next — only the decide+deduct pair below is atomic:
            // if the deduction fails (e.g. no deposit held), it's rolled
            // back together so the expense is never left pointing at a
            // half-made decision with nothing behind it; the expense
            // simply remains on record as not-yet-charged, exactly as if
            // "Landlord" had been chosen.
            try {
                DB::transaction(function () use ($expense, $data) {
                    // Server-side tenancy/property association check — the
                    // client-submitted id is never trusted beyond "does
                    // this row exist" (exists:tenancies,id above); it must
                    // also actually be a tenancy at the chosen house.
                    $tenancy = Tenancy::where('house_id', $data['house_id'])->whereKey((int) $data['liability_tenancy_id'])->first();

                    if (! $tenancy) {
                        throw new RuntimeException('That tenancy has never occupied this unit — select one from the list.');
                    }

                    app(TenantLiabilityDecisionService::class)->decide(
                        $expense, 'tenant_liable', $tenancy->id, $data['deduction_reason'], Auth::id(),
                    );

                    $deduction = app(DepositService::class)->recordDeduction(
                        $tenancy,
                        (float) $data['deduction_amount'],
                        $data['deduction_reason'],
                        $data['expense_date'],
                        Auth::id(),
                        $this->deduction_evidence ?: null,
                        awaitingTenantAcknowledgement: true,
                    );

                    $expense->update(['deposit_deduction_id' => $deduction->id]);
                });

                session()->flash('status', 'Expense recorded. The proposed deposit charge is awaiting the tenant\'s response.');
            } catch (RuntimeException|InvalidArgumentException $e) {
                session()->flash('error', "Expense recorded, but the deposit charge wasn't recorded: {$e->getMessage()}");
            }
        } else {
            session()->flash('status', 'Expense recorded.');
        }

        $this->redirectRoute('manage.expenses.index', navigate: true);
    }

    public function render()
    {
        $landlordId = Auth::id();

        $categories = ExpenseCategory::active()->orderBy('name')->get();
        if ($this->expense_category_id && ! $categories->contains('id', $this->expense_category_id)) {
            // The category this expense was originally tagged with has since
            // been deactivated — keep it selectable here so editing other
            // fields doesn't silently reassign it to whatever sorts first.
            $categories = $categories->push(ExpenseCategory::find($this->expense_category_id))->filter()->sortBy('name')->values();
        }

        $candidateTenancies = $this->candidateTenancies();

        return view('livewire.expenses.form', [
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'blocks' => $this->property_id ? Block::where('property_id', $this->property_id)->orderBy('name')->get() : collect(),
            'houses' => $this->block_id ? House::where('block_id', $this->block_id)->orderBy('house_number')->get() : collect(),
            'categories' => $categories,
            'candidateTenancies' => $candidateTenancies,
            'selectedTenancy' => $this->liability_tenancy_id ? $candidateTenancies->firstWhere('id', $this->liability_tenancy_id) : null,
        ]);
    }
}
