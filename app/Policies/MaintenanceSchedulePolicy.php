<?php

namespace App\Policies;

use App\Models\MaintenanceSchedule;
use App\Models\User;

class MaintenanceSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, MaintenanceSchedule $schedule): bool
    {
        return $schedule->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, MaintenanceSchedule $schedule): bool
    {
        return $schedule->property->landlord_id === $user->id;
    }

    public function delete(User $user, MaintenanceSchedule $schedule): bool
    {
        return $schedule->property->landlord_id === $user->id;
    }
}
