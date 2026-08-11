<?php

namespace App\Policies;

use App\Models\Income;
use App\Models\User;

class IncomePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Income $income): bool
    {
        return $income->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Income $income): bool
    {
        return $income->property->landlord_id === $user->id;
    }

    public function delete(User $user, Income $income): bool
    {
        return $income->property->landlord_id === $user->id;
    }
}
