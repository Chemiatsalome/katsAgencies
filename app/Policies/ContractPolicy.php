<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        if ($contract->tenancy->tenant->user_id === $user->id) {
            return true;
        }

        return Contract::accessibleBy($user)->whereKey($contract->id)->exists();
    }

    public function create(User $user): bool
    {
        // Deliberately its own permission, not tenancies.terminate — the
        // two used to be the same checkbox (no dedicated contracts
        // permission existed), which meant granting termination rights
        // silently also granted contract management.
        return $user->hasRole('landlord') || $user->hasManagerPermission('tenancies.manage_contracts');
    }

    public function update(User $user, Contract $contract): bool
    {
        if (! Contract::accessibleBy($user)->whereKey($contract->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('tenancies.manage_contracts');
    }
}
