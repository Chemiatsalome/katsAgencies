<?php

namespace App\Policies;

use App\Models\TenantInvitation;
use App\Models\User;

class TenantInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToLandlord($user, $invitation);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToLandlord($user, $invitation);
    }

    public function delete(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToLandlord($user, $invitation);
    }

    private function belongsToLandlord(User $user, TenantInvitation $invitation): bool
    {
        return $invitation->house->block->property->landlord_id === $user->id;
    }
}
