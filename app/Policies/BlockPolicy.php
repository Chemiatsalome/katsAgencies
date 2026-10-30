<?php

namespace App\Policies;

use App\Models\Block;
use App\Models\Property;
use App\Models\User;

class BlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('manager');
    }

    public function view(User $user, Block $block): bool
    {
        return Block::accessibleBy($user)->whereKey($block->id)->exists();
    }

    // $property is the parent the route resolved (properties/{property}/blocks/create)
    // — without checking it too, a manager or landlord could create a block
    // under a property they don't own just by editing the URL, since the
    // capability check alone says nothing about which property.
    public function create(User $user, ?Property $property = null): bool
    {
        if (! $user->hasRole('landlord') && ! $user->hasManagerPermission('properties.create')) {
            return false;
        }

        if ($property === null) {
            return true;
        }

        return Property::accessibleBy($user)->whereKey($property->id)->exists();
    }

    public function update(User $user, Block $block): bool
    {
        if (! Block::accessibleBy($user)->whereKey($block->id)->exists()) {
            return false;
        }

        return $user->hasRole('landlord') || $user->hasManagerPermission('properties.edit');
    }

    public function delete(User $user, Block $block): bool
    {
        // Structural deletion of a landlord-owned property's blocks is never
        // delegable — mirrors PropertyPolicy::delete().
        return $user->hasRole('landlord') && $block->property->landlord_id === $user->id;
    }
}
