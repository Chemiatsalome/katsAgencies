<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $expense->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->property->landlord_id === $user->id;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $expense->property->landlord_id === $user->id;
    }
}
