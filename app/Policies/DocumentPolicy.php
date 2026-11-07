<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\DepositDeduction;
use App\Models\DepositPayment;
use App\Models\Disbursement;
use App\Models\Document;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\TenancyNotice;
use App\Models\Tenant;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->accessibleByUser($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('landlord');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->accessibleByUser($user, $document);
    }

    /**
     * Routes through each subject's own accessibleBy() scope — the same
     * property-aware check every other module in the app uses — rather than
     * resolving a landlord_id and comparing it straight against $user->id.
     * That comparison is only ever true for the landlord themselves: for a
     * manager, $user->id is the manager's own id, which never equals a
     * landlord_id, so it silently denied every manager regardless of their
     * actual permissions.
     */
    private function accessibleByUser(User $user, Document $document): bool
    {
        $subject = $document->documentable;

        return match (true) {
            $subject instanceof Property => Property::accessibleBy($user)->whereKey($subject->id)->exists(),
            $subject instanceof House => House::accessibleBy($user)->whereKey($subject->id)->exists(),
            // Every Tenancy-attached category (signed lease, the tenant's
            // own ID upload) is something the tenant it belongs to must be
            // able to see — Tenancy::accessibleBy() only ever covers the
            // landlord/manager side (it chains through House/Block/
            // Property), so it never granted the tenant themselves access
            // to their own contract or ID document.
            // Every Tenancy-attached category (signed lease, the tenant's
            // own ID upload) is something the tenant it belongs to must be
            // able to see — Tenancy::accessibleBy() only ever covers the
            // landlord/manager side (it chains through House/Block/
            // Property), so it never granted the tenant themselves access
            // to their own contract or ID document.
            $subject instanceof Tenancy => Tenancy::accessibleBy($user)->whereKey($subject->id)->exists()
                || $subject->tenant_id === $user->tenant?->id,
            // A tenant can have tenancies with more than one landlord over
            // time (or, in theory, simultaneously) — Tenant::accessibleBy()
            // grants access if ANY of them is accessible, not just whichever
            // arbitrary tenancy row a bare first() happens to return.
            $subject instanceof Tenant => Tenant::accessibleBy($user)->whereKey($subject->id)->exists(),
            $subject instanceof MaintenanceTask => MaintenanceTask::accessibleBy($user)->whereKey($subject->id)->exists(),
            $subject instanceof Complaint => Complaint::accessibleBy($user)->whereKey($subject->id)->exists(),
            // Payment evidence (e.g. a tenant's offline-payment proof) —
            // scoped via its tenancy, same as every other tenancy-rooted
            // subject above; Payment has no accessibleBy() of its own.
            // Previously landlord/manager-only, same bug the Tenancy branch
            // above was already fixed for: the tenant who uploaded this
            // evidence in the first place couldn't view it back.
            $subject instanceof Payment => Tenancy::accessibleBy($user)->whereKey($subject->tenancy_id)->exists()
                || $subject->tenancy?->tenant_id === $user->tenant?->id,
            // Deposit-payment evidence — same reasoning as Payment above,
            // added alongside the deposit offline-claim flow this document
            // category now comes from.
            $subject instanceof DepositPayment => Tenancy::accessibleBy($user)->whereKey($subject->tenancy_id)->exists()
                || $subject->tenancy?->tenant_id === $user->tenant?->id,
            // Phase 6 audit, P6-H-02: deposit-deduction evidence had no case
            // here at all, so it fell to default => false for everyone but
            // admin — the tenant disputing a deduction, and the landlord who
            // recorded it, were both blocked from viewing the evidence a
            // link on their own page offered. Same tenancy-rooted reasoning
            // as Payment/DepositPayment above.
            $subject instanceof DepositDeduction => Tenancy::accessibleBy($user)->whereKey($subject->tenancy_id)->exists()
                || $subject->tenancy?->tenant_id === $user->tenant?->id,
            // Phase 6 audit follow-up: tenancy-notice and change-request
            // evidence is attached directly to the TenancyNotice/
            // TenancyChangeRequest itself (confirmed in
            // TenancyNoticeService/TenancyChangeRequestService — not to the
            // Tenancy), and had no case here either, same gap as
            // DepositDeduction above. Both models have their own tenancy_id
            // + tenancy() exactly like Payment/DepositPayment/
            // DepositDeduction, so the same tenancy-rooted scoping applies.
            $subject instanceof TenancyNotice => Tenancy::accessibleBy($user)->whereKey($subject->tenancy_id)->exists()
                || $subject->tenancy?->tenant_id === $user->tenant?->id,
            $subject instanceof TenancyChangeRequest => Tenancy::accessibleBy($user)->whereKey($subject->tenancy_id)->exists()
                || $subject->tenancy?->tenant_id === $user->tenant?->id,
            // Payout evidence (e.g. an admin's bank/M-Pesa confirmation slip
            // for a disbursement) — the landlord it was paid out to, only.
            // Admins see everything via the Gate::before bypass already.
            $subject instanceof Disbursement => $subject->landlord_id === $user->landlordId(),
            // Expense has no per-manager property scope anywhere in the app
            // yet (see Expenses/Index.php, which scopes by landlord_id
            // alone) — matched here rather than inventing a stricter rule
            // just for its documents.
            $subject instanceof Expense => $subject->property->landlord_id === $user->landlordId(),
            default => false,
        };
    }
}
