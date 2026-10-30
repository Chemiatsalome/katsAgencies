<?php

namespace App\Policies;

use App\Models\TenantLiabilityDecision;
use App\Models\User;

/**
 * Tier 2 (design approved 2026-10-25). Landlord-only, never delegable to a
 * manager — matches this app's existing "Finance/Deposits are never
 * delegable" boundary (see PermissionSeeder::managerPermissionGroups(),
 * which deliberately offers no finance permission beyond view-only rent
 * status). A liability decision gates a deposit deduction, so it stays on
 * the same side of that boundary as the deduction itself.
 */
class TenantLiabilityDecisionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function update(User $user, TenantLiabilityDecision $decision): bool
    {
        return $user->hasRole('landlord');
    }

    public function delete(User $user, TenantLiabilityDecision $decision): bool
    {
        return $user->hasRole('landlord');
    }
}
