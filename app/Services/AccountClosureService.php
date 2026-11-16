<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Models\Manager;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;

/**
 * Phase 4 (account lifecycle), points E/F: closing an account is never a
 * delete — it only ever revokes login (User.status), exactly like any
 * other deactivation already enforced by EnsureAccountIsActive. The
 * Tenant/landlord business record, every tenancy, payment, deposit,
 * contract and manager-assignment history stays exactly as it is. Each
 * close*() method returns an array of blocking reasons; an empty array
 * means closure went ahead, a non-empty one means nothing was changed.
 */
class AccountClosureService
{
    /** @return string[] blockers, empty if the tenant's login was closed */
    public function closeTenantAccount(Tenant $tenant): array
    {
        $blockers = [];

        if ($tenant->tenancies()->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            $blockers[] = 'You have an active tenancy. It must end before your account can be closed.';
        }

        $tenancyIds = $tenant->tenancies()->pluck('id');

        if (Payment::whereIn('tenancy_id', $tenancyIds)->where('status', 'awaiting_landlord_confirmation')->exists()) {
            $blockers[] = 'You have a rent payment awaiting your landlord\'s confirmation.';
        }

        if (\App\Models\DepositPayment::whereIn('tenancy_id', $tenancyIds)->where('status', 'awaiting_landlord_confirmation')->exists()) {
            $blockers[] = 'You have a deposit payment awaiting your landlord\'s confirmation.';
        }

        if (\App\Models\MpesaTransaction::whereIn('tenancy_id', $tenancyIds)->where('status', 'pending')->exists()) {
            $blockers[] = 'You have an M-Pesa payment still being processed.';
        }

        if ($tenant->tenancies->contains(fn (Tenancy $t) => $t->depositHeldBalance() > 0)) {
            $blockers[] = 'Your landlord still holds part of your deposit, with no refund recorded yet.';
        }

        if (\App\Models\DepositDeduction::whereIn('tenancy_id', $tenancyIds)->whereNotNull('disputed_at')->whereNull('dispute_resolved_at')->exists()) {
            $blockers[] = 'You have an unresolved deposit deduction dispute.';
        }

        if ($blockers) {
            return $blockers;
        }

        $tenant->user?->update(['status' => 'inactive']);

        return [];
    }

    /** @return string[] blockers, empty if the landlord's login was closed */
    public function closeLandlordAccount(User $landlord): array
    {
        $blockers = [];

        $propertyIds = $landlord->properties()->pluck('id');

        if (Tenancy::whereIn('status', Tenancy::OCCUPYING_STATUSES)
            ->whereHas('house.block', fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->exists()) {
            $blockers[] = 'You have an active tenancy. All tenancies must end before your account can be closed.';
        }

        if (Manager::where('landlord_id', $landlord->id)->exists()) {
            $blockers[] = 'You still have manager delegations on file. Remove every manager first.';
        }

        if (Disbursement::where('landlord_id', $landlord->id)->where('status', 'awaiting_landlord_confirmation')->exists()) {
            $blockers[] = 'You have a payout awaiting your confirmation.';
        }

        $tenancyIds = Tenancy::whereHas('house.block', fn ($q) => $q->whereIn('property_id', $propertyIds))->pluck('id');

        if (Payment::whereIn('tenancy_id', $tenancyIds)->where('status', 'awaiting_landlord_confirmation')->exists()
            || \App\Models\DepositPayment::whereIn('tenancy_id', $tenancyIds)->where('status', 'awaiting_landlord_confirmation')->exists()) {
            $blockers[] = 'A tenant payment is still awaiting your confirmation.';
        }

        if (\App\Models\DepositDeduction::whereIn('tenancy_id', $tenancyIds)->whereNotNull('disputed_at')->whereNull('dispute_resolved_at')->exists()) {
            $blockers[] = 'You have an unresolved deposit deduction dispute.';
        }

        if (Tenancy::whereIn('id', $tenancyIds)->get()->contains(fn (Tenancy $t) => $t->depositHeldBalance() > 0)) {
            $blockers[] = 'You still hold tenant deposits that have not been refunded or fully accounted for.';
        }

        if ($blockers) {
            return $blockers;
        }

        $landlord->update(['status' => 'inactive']);

        return [];
    }
}
