<?php

namespace App\Policies;

use App\Models\Tenancy;
use App\Models\User;

class TenancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Tenancy $tenancy): bool
    {
        if ($tenancy->tenant->user_id === $user->id) {
            return true;
        }

        return Tenancy::accessibleBy($user)->whereKey($tenancy->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('tenancies.create');
    }

    public function update(User $user, Tenancy $tenancy): bool
    {
        if (! Tenancy::accessibleBy($user)->whereKey($tenancy->id)->exists()) {
            return false;
        }

        // Terminating/ending a tenancy is gated by tenancies.terminate; other
        // edits (e.g. rent adjustments) fall under the same permission since
        // there's no separate "edit" checkbox in the spec's permission list.
        return $user->hasRole('landlord') || $user->hasManagerPermission('tenancies.terminate');
    }

    public function delete(User $user, Tenancy $tenancy): bool
    {
        return $user->hasRole('landlord') && Tenancy::accessibleBy($user)->whereKey($tenancy->id)->exists();
    }
}
