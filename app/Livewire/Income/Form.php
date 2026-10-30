<?php

namespace App\Livewire\Income;

use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?Income $income = null;

    public ?int $property_id = null;

    public ?int $income_category_id = null;

    public string $amount = '';

    public string $income_date;

    public string $description = '';

    public function mount(?Income $income = null): void
    {
        if ($income?->exists) {
            $this->authorize('update', $income);
            $this->income = $income;
            $this->property_id = $income->property_id;
            $this->income_category_id = $income->income_category_id;
            $this->amount = (string) $income->amount;
            $this->income_date = $income->income_date->format('Y-m-d');
            $this->description = (string) $income->description;
        } else {
            $this->authorize('create', Income::class);
            $this->income_date = now()->format('Y-m-d');
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'income_category_id' => ['required', 'exists:income_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'income_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $property = Property::findOrFail($data['property_id']);
        $this->authorize('update', $property);

        if ($this->income) {
            $this->income->update($data);
            session()->flash('status', 'Income updated.');
        } else {
            $data['recorded_by'] = Auth::id();
            Income::create($data);
            session()->flash('status', 'Income recorded.');
        }

        $this->redirectRoute('manage.income.index', navigate: true);
    }

    public function render()
    {
        $landlordId = Auth::id();

        $categories = IncomeCategory::active()->orderBy('name')->get();
        if ($this->income_category_id && ! $categories->contains('id', $this->income_category_id)) {
            // The category this income was originally tagged with has since
            // been deactivated — keep it selectable here so editing other
            // fields doesn't silently reassign it to whatever sorts first.
            $categories = $categories->push(IncomeCategory::find($this->income_category_id))->filter()->sortBy('name')->values();
        }

        return view('livewire.income.form', [
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'categories' => $categories,
        ]);
    }
}
