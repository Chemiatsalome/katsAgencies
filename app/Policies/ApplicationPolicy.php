<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Application $application): bool
    {
        return $application->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Application $application): bool
    {
        return $application->property->landlord_id === $user->id;
    }

    public function delete(User $user, Application $application): bool
    {
        return $application->property->landlord_id === $user->id;
    }
}
