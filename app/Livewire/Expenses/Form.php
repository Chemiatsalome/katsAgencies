<?php

namespace App\Livewire\Expenses;

use App\Models\Block;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?int $property_id = null;

    public ?int $block_id = null;

    public ?int $house_id = null;

    public ?int $expense_category_id = null;

    public string $vendor = '';

    public string $amount = '';

    public string $expense_date;

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('create', Expense::class);
        $this->expense_date = now()->format('Y-m-d');
    }

    public function updatedPropertyId(): void
    {
        $this->block_id = null;
        $this->house_id = null;
    }

    public function save(): void
    {
        $data = $this->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'block_id' => ['nullable', 'exists:blocks,id'],
            'house_id' => ['nullable', 'exists:houses,id'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $property = Property::findOrFail($data['property_id']);
        $this->authorize('update', $property);

        $data['recorded_by'] = Auth::id();

        Expense::create($data);

        session()->flash('status', 'Expense recorded.');
        $this->redirectRoute('manage.expenses.index', navigate: true);
    }

    public function render()
    {
        $landlordId = Auth::id();

        return view('livewire.expenses.form', [
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'blocks' => $this->property_id ? Block::where('property_id', $this->property_id)->orderBy('name')->get() : collect(),
            'houses' => $this->block_id ? House::where('block_id', $this->block_id)->orderBy('house_number')->get() : collect(),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
        ]);
    }
}
