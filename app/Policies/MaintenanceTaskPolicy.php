<?php

namespace App\Policies;

use App\Models\MaintenanceTask;
use App\Models\User;

class MaintenanceTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, MaintenanceTask $task): bool
    {
        return $task->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, MaintenanceTask $task): bool
    {
        return $task->property->landlord_id === $user->id;
    }

    public function delete(User $user, MaintenanceTask $task): bool
    {
        return $task->property->landlord_id === $user->id;
    }
}
