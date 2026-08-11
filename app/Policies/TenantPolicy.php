<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        if ($tenant->user_id === $user->id) {
            return true;
        }

        return $this->belongsToLandlord($user, $tenant);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->belongsToLandlord($user, $tenant);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->belongsToLandlord($user, $tenant);
    }

    private function belongsToLandlord(User $user, Tenant $tenant): bool
    {
        return $tenant->tenancies()
            ->whereHas('house.block.property', fn ($query) => $query->where('landlord_id', $user->id))
            ->exists();
    }
}
