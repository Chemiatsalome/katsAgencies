<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        if ($tenant->user_id === $user->id) {
            return true;
        }

        return Tenant::accessibleBy($user)->whereKey($tenant->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('tenants.create');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        if (! Tenant::accessibleBy($user)->whereKey($tenant->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('tenants.edit');
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        // Deleting a tenant record outright is never delegable to a manager.
        return $user->hasRole('landlord') && Tenant::accessibleBy($user)->whereKey($tenant->id)->exists();
    }
}
