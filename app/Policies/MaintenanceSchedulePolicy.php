<?php

namespace App\Policies;

use App\Models\MaintenanceSchedule;
use App\Models\User;

class MaintenanceSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, MaintenanceSchedule $schedule): bool
    {
        return MaintenanceSchedule::accessibleBy($user)->whereKey($schedule->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.schedule');
    }

    public function update(User $user, MaintenanceSchedule $schedule): bool
    {
        if (! MaintenanceSchedule::accessibleBy($user)->whereKey($schedule->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.schedule');
    }

    public function delete(User $user, MaintenanceSchedule $schedule): bool
    {
        return $user->hasRole('landlord') && MaintenanceSchedule::accessibleBy($user)->whereKey($schedule->id)->exists();
    }
}
