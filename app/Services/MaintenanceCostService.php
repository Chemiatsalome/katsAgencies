<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MaintenanceTask;

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
            'expense_category_id' => $category->id,
            'vendor' => $task->vendor?->name,
            'amount' => $task->actual_cost,
            'expense_date' => $task->completed_at?->toDateString() ?? now()->toDateString(),
            'description' => $task->title,
        ]);

        $task->update(['expense_id' => $expense->id]);

        return $expense;
    }
}
