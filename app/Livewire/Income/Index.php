<?php

namespace App\Livewire\Income;

use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $categoryId = 'all';

    #[Url]
    public string $month = '';

    #[Url]
    public int $perPage = 25;

    public function delete(int $incomeId): void
    {
        $income = Income::findOrFail($incomeId);
        $this->authorize('delete', $income);
        $income->delete();
    }

    public function render()
    {
        $landlordId = Auth::id();

        $query = Income::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['property', 'category']);

        if ($this->propertyId !== 'all') {
            $query->where('property_id', (int) $this->propertyId);
        }

        if ($this->categoryId !== 'all') {
            $query->where('income_category_id', (int) $this->categoryId);
        }

        if ($this->month) {
            $query->whereRaw("DATE_FORMAT(income_date, '%Y-%m') = ?", [$this->month]);
        }

        $total = (clone $query)->sum('amount');

        return view('livewire.income.index', [
            'incomes' => $query->orderByDesc('income_date')->paginate($this->perPage),
            'total' => $total,
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'categories' => IncomeCategory::active()->orderBy('name')->get(),
        ]);
    }
}
