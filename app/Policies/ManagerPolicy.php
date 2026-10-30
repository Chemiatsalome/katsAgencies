<?php

namespace App\Policies;

use App\Models\Manager;
use App\Models\User;

class ManagerPolicy
{
    // Delegating access to a manager, or managing who has it, is a landlord-only
    // capability — a manager can never manage other managers (no sub-delegation).
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Manager $manager): bool
    {
        return $manager->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Manager $manager): bool
    {
        return $manager->landlord_id === $user->id;
    }

    public function delete(User $user, Manager $manager): bool
    {
        return $manager->landlord_id === $user->id;
    }
}
