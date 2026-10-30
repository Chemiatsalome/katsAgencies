<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Complaint $complaint): bool
    {
        if ($complaint->tenant_id && $complaint->tenant?->user_id === $user->id) {
            return true;
        }

        return Complaint::accessibleBy($user)->whereKey($complaint->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('tenant');
    }

    public function update(User $user, Complaint $complaint): bool
    {
        if (! Complaint::accessibleBy($user)->whereKey($complaint->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('complaints.respond');
    }

    public function delete(User $user, Complaint $complaint): bool
    {
        return $user->hasRole('landlord') && Complaint::accessibleBy($user)->whereKey($complaint->id)->exists();
    }
}
