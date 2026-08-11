<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->belongsToLandlord($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->belongsToLandlord($user, $document);
    }

    private function belongsToLandlord(User $user, Document $document): bool
    {
        $subject = $document->documentable;

        $landlordId = match (true) {
            $subject instanceof Property => $subject->landlord_id,
            $subject instanceof House => $subject->block->property->landlord_id,
            $subject instanceof Tenancy => $subject->house->block->property->landlord_id,
            $subject instanceof Tenant => $subject->tenancies()->first()?->house->block->property->landlord_id,
            $subject instanceof Expense => $subject->property->landlord_id,
            $subject instanceof MaintenanceTask => $subject->property->landlord_id,
            default => null,
        };

        return $landlordId === $user->id;
    }
}
