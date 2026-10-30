<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Application $application): bool
    {
        return Application::accessibleBy($user)->whereKey($application->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('applications.review');
    }

    public function update(User $user, Application $application): bool
    {
        if (! Application::accessibleBy($user)->whereKey($application->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord')
            || $user->hasManagerPermission('applications.review')
            || $user->hasManagerPermission('applications.approve');
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->hasRole('landlord') && Application::accessibleBy($user)->whereKey($application->id)->exists();
    }
}
