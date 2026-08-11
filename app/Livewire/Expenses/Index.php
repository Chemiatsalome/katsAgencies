<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
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

    public function delete(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        $this->authorize('delete', $expense);
        $expense->delete();
    }

    public function render()
    {
        $landlordId = Auth::id();

        $query = Expense::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['property', 'house', 'category']);

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

        return view('livewire.expenses.index', [
            'expenses' => $query->orderByDesc('expense_date')->paginate($this->perPage),
            'total' => $total,
            'properties' => Property::forLandlord($landlordId)->orderBy('name')->get(),
            'categories' => ExpenseCategory::active()->orderBy('name')->get(),
        ]);
    }
}
