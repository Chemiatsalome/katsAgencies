<?php

namespace App\Policies;

use App\Models\MaintenanceTask;
use App\Models\User;

class MaintenanceTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, MaintenanceTask $task): bool
    {
        return MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.create');
    }

    public function update(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        // Covers assign/schedule/resolve edits — any of the three related
        // permissions is enough to update a task; the components themselves
        // gate the specific action being taken (assign vs. resolve, etc.).
        return $user->hasRole('landlord')
            || $user->hasManagerPermission('maintenance.schedule')
            || $user->hasManagerPermission('maintenance.assign')
            || $user->hasManagerPermission('maintenance.resolve');
    }

    public function delete(User $user, MaintenanceTask $task): bool
    {
        return $user->hasRole('landlord') && MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists();
    }
}
