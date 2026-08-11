<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, Complaint $complaint): bool
    {
        if ($complaint->tenant_id && $complaint->tenant?->user_id === $user->id) {
            return true;
        }

        return $this->belongsToLandlord($user, $complaint);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord') || $user->hasRole('tenant');
    }

    public function update(User $user, Complaint $complaint): bool
    {
        return $this->belongsToLandlord($user, $complaint);
    }

    public function delete(User $user, Complaint $complaint): bool
    {
        return $this->belongsToLandlord($user, $complaint);
    }

    private function belongsToLandlord(User $user, Complaint $complaint): bool
    {
        if ($complaint->house) {
            return $complaint->house->block->property->landlord_id === $user->id;
        }

        if ($complaint->property) {
            return $complaint->property->landlord_id === $user->id;
        }

        return false;
    }
}
