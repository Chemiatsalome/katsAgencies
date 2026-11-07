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

    /**
     * Phase 7A, finding 4: kept as a broad legacy aggregate only — true if
     * ANY of the three related permissions is held — for Laravel's generic
     * ability-resolution conventions (e.g. a future @can('update', $task)).
     * No write action in Maintenance\Index.php calls this anymore; each one
     * now calls the specific ability below for exactly what it does.
     * Treating "can update" as "can do absolutely anything to this task" was
     * the bug: a manager granted only maintenance.schedule (meant to let
     * them set appointment dates) could also mark tasks complete, record
     * costs, and trigger a deposit deduction.
     */
    public function update(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord')
            || $user->hasManagerPermission('maintenance.schedule')
            || $user->hasManagerPermission('maintenance.assign')
            || $user->hasManagerPermission('maintenance.resolve');
    }

    /** Scheduling/rescheduling an appointment — never, on its own, vendor-assignment or financial/liability authority. */
    public function schedule(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.schedule');
    }

    /** Assigning a vendor/responsible person — never, on its own, scheduling or financial/liability authority. */
    public function assign(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.assign');
    }

    /**
     * Phase 7A follow-up: narrowed to operational progress and completion
     * only — reviewing, starting progress, marking complete, closing.
     * Recording a cost and deciding liability/deposit deductions used to sit
     * behind this same permission too; a manager granted only operational
     * resolve authority could still record expenses or trigger a deposit
     * deduction, which is exactly the "financial authority must not follow
     * from operational authority alone" gap this split closes. Those two now
     * have their own abilities below, checked independently (and in addition
     * to this one, since completing/deciding liability still requires the
     * underlying operational action to be authorized too).
     */
    public function resolve(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.resolve');
    }

    /** Recording a maintenance cost/expense — a task can be marked complete with resolve alone (e.g. at zero cost); actually recording a non-zero cost needs this specifically. */
    public function manage_costs(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.manage_costs');
    }

    /** Deciding tenant/landlord liability and initiating a deposit deduction — never granted by resolve, manage_costs, schedule, or assign alone. */
    public function manage_liability(User $user, MaintenanceTask $task): bool
    {
        if (! MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('maintenance.manage_liability');
    }

    public function delete(User $user, MaintenanceTask $task): bool
    {
        return $user->hasRole('landlord') && MaintenanceTask::accessibleBy($user)->whereKey($task->id)->exists();
    }
}
