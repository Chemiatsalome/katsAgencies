<?php

namespace App\Policies;

use App\Models\Block;
use App\Models\House;
use App\Models\User;

class HousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, House $house): bool
    {
        return House::accessibleBy($user)->whereKey($house->id)->exists();
    }

    // $block is the parent the route resolved (blocks/{block}/houses/create)
    // — mirrors BlockPolicy::create's fix for the same class of IDOR.
    public function create(User $user, ?Block $block = null): bool
    {
        if (! $user->hasRole('landlord') && ! $user->hasManagerPermission('properties.create')) {
            return false;
        }

        if ($block === null) {
            return true;
        }

        return Block::accessibleBy($user)->whereKey($block->id)->exists();
    }

    public function update(User $user, House $house): bool
    {
        if (! House::accessibleBy($user)->whereKey($house->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('properties.edit');
    }

    public function delete(User $user, House $house): bool
    {
        // Structural deletion of a landlord-owned property's units is never
        // delegable — mirrors PropertyPolicy::delete().
        return $user->hasRole('landlord') && $house->block->property->landlord_id === $user->id;
    }
}
