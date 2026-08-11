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
    public ?int $property_id = null;

    public ?int $income_category_id = null;

    public string $amount = '';

    public string $income_date;

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('create', Income::class);
        $this->income_date = now()->format('Y-m-d');
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

        $data['recorded_by'] = Auth::id();

        Income::create($data);

        session()->flash('status', 'Income recorded.');
        $this->redirectRoute('manage.income.index', navigate: true);
    }

    public function render()
    {
        $landlordId = Auth::id();

        return view('livewire.income.form', [
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'categories' => IncomeCategory::active()->orderBy('name')->get(),
        ]);
    }
}
