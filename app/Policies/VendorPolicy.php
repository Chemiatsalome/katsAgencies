<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $vendor->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $vendor->landlord_id === $user->id;
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $vendor->landlord_id === $user->id;
    }
}
