<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Property $property): bool
    {
        return Property::accessibleBy($user)->whereKey($property->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('properties.create');
    }

    public function update(User $user, Property $property): bool
    {
        if (! Property::accessibleBy($user)->whereKey($property->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('properties.edit');
    }

    public function delete(User $user, Property $property): bool
    {
        // Deleting a landlord-owned property is never delegable — only the
        // landlord (or admin, via the global Gate::before bypass) can do this.
        return $user->hasRole('landlord') && $property->landlord_id === $user->id;
    }
}
