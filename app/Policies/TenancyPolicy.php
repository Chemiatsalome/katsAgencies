<?php

namespace App\Policies;

use App\Models\Tenancy;
use App\Models\User;

class TenancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Tenancy $tenancy): bool
    {
        if ($tenancy->tenant->user_id === $user->id) {
            return true;
        }

        return $this->belongsToLandlord($user, $tenancy);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Tenancy $tenancy): bool
    {
        return $this->belongsToLandlord($user, $tenancy);
    }

    public function delete(User $user, Tenancy $tenancy): bool
    {
        return $this->belongsToLandlord($user, $tenancy);
    }

    private function belongsToLandlord(User $user, Tenancy $tenancy): bool
    {
        return $tenancy->house->block->property->landlord_id === $user->id;
    }
}
