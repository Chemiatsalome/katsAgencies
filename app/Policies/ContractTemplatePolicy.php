<?php

namespace App\Policies;

use App\Models\ContractTemplate;
use App\Models\User;

// Contract templates are landlord business configuration — same posture as
// LandlordSetting and the Finance permission group — never delegable to a
// manager, regardless of what other permissions the landlord has granted.
class ContractTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function view(User $user, ContractTemplate $template): bool
    {
        return $user->hasRole('landlord') && $template->landlord_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, ContractTemplate $template): bool
    {
        return $user->hasRole('landlord') && $template->landlord_id === $user->id;
    }

    public function delete(User $user, ContractTemplate $template): bool
    {
        return $user->hasRole('landlord') && $template->landlord_id === $user->id;
    }
}
