<?php

namespace App\Policies;

use App\Models\Block;
use App\Models\User;

class BlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Block $block): bool
    {
        return $block->property->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, Block $block): bool
    {
        return $block->property->landlord_id === $user->id;
    }

    public function delete(User $user, Block $block): bool
    {
        return $block->property->landlord_id === $user->id;
    }
}
