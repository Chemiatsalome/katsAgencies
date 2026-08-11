<?php

namespace App\Policies;

use App\Models\House;
use App\Models\User;

class HousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, House $house): bool
    {
        return $house->block->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, House $house): bool
    {
        return $house->block->property->landlord_id === $user->id;
    }

    public function delete(User $user, House $house): bool
    {
        return $house->block->property->landlord_id === $user->id;
    }
}
