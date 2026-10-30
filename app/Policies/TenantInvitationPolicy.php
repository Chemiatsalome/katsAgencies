<?php

namespace App\Policies;

use App\Models\TenantInvitation;
use App\Models\User;

class TenantInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, TenantInvitation $invitation): bool
    {
        return TenantInvitation::accessibleBy($user)->whereKey($invitation->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasManagerPermission('tenants.create');
    }

    public function update(User $user, TenantInvitation $invitation): bool
    {
        if (! TenantInvitation::accessibleBy($user)->whereKey($invitation->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('tenants.create');
    }

    public function delete(User $user, TenantInvitation $invitation): bool
    {
        if (! TenantInvitation::accessibleBy($user)->whereKey($invitation->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('tenants.create');
    }
}
