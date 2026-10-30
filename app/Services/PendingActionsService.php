<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\DepositDeduction;
use App\Models\Disbursement;
use App\Models\DueDayChangeRequest;
use App\Models\LateFeeWaiverRequest;
use App\Models\MaintenanceTask;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\SupportTicket;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\TenancyNotice;
use App\Models\TenancyRenewalRequest;
use App\Models\Tenant;
use App\Models\User;

/**
 * Per-nav-item action-badge counts — one method per sidebar link that can
 * show a red number. Every method is scoped to exactly what the given user
 * can access (accessibleBy()-style scopes already used elsewhere), so a
 * manager sees only their own assigned slice, never their landlord's whole
 * portfolio, and every count answers "how many things actually need this
 * specific person's attention right now" rather than a platform-wide total.
 */
class PendingActionsService
{
    /** Open maintenance tasks plus complaints not yet converted into one — mirrors Maintenance\Index's dashboard counts exactly. */
    public function maintenanceCount(User $user): int
    {
        $openTasks = MaintenanceTask::accessibleBy($user)->open()->count();
        $unconvertedComplaints = Complaint::accessibleBy($user)->unresolved()->whereDoesntHave('maintenanceTask')->count();

        return $openTasks + $unconvertedComplaints;
    }

    /** Tenancies with an incomplete deposit, plus deductions the tenant has disputed and nobody's resolved yet — both need this landlord/manager to act. */
    public function depositsOutstandingCount(User $user): int
    {
        $outstanding = Tenancy::accessibleBy($user)
            ->get()
            ->filter(fn (Tenancy $t) => $t->depositOutstanding() > 0)
            ->count();

        $disputed = DepositDeduction::whereNotNull('disputed_at')
            ->whereNull('dispute_resolved_at')
            ->whereHas('tenancy', fn ($q) => $q->accessibleBy($user))
            ->count();

        return $outstanding + $disputed;
    }

    /** Tenancies with rent genuinely overdue right now. */
    public function arrearsCount(User $user): int
    {
        return RentCharge::overdue()
            ->whereHas('tenancy', fn ($q) => $q->accessibleBy($user))
            ->distinct('tenancy_id')
            ->count('tenancy_id');
    }

    /** Notices with a tenant response still awaiting this landlord/manager's decision. */
    public function tenancyNoticesAwaitingDecisionCount(User $user): int
    {
        return TenancyNotice::accessibleBy($user)->awaitingDecision()->count();
    }

    /** Structured change requests still awaiting this landlord/manager's decision. */
    public function tenancyChangeRequestsAwaitingDecisionCount(User $user): int
    {
        return TenancyChangeRequest::accessibleBy($user)->awaitingDecision()->count();
    }

    /** Renewal requests — including a countered alternative awaiting the tenant — still awaiting action from this landlord/manager. */
    public function tenancyRenewalRequestsAwaitingDecisionCount(User $user): int
    {
        return TenancyRenewalRequest::accessibleBy($user)->awaitingDecision()->count();
    }

    /** Late-fee waiver requests still awaiting this landlord/manager's decision. */
    public function lateFeeWaiverRequestsAwaitingDecisionCount(User $user): int
    {
        return LateFeeWaiverRequest::accessibleBy($user)->pending()->count();
    }

    /** Rent due-day change requests still awaiting this landlord/manager's decision. */
    public function dueDayChangeRequestsAwaitingDecisionCount(User $user): int
    {
        return DueDayChangeRequest::accessibleBy($user)->pending()->count();
    }

    /** Tenant-submitted offline-payment claims still awaiting this landlord/manager's confirmation or dispute. */
    public function paymentsAwaitingConfirmationCount(User $user): int
    {
        return Payment::awaitingConfirmation()->whereHas('tenancy', fn ($q) => $q->accessibleBy($user))->count();
    }

    /**
     * Payouts this landlord has been told about but hasn't yet confirmed or
     * disputed. Landlord-only, like the rest of the financial module —
     * Finance Overview and My Payouts are never delegable to a manager.
     */
    public function payoutsAwaitingLandlordConfirmationCount(User $user): int
    {
        if (! $user->hasRole('landlord')) {
            return 0;
        }

        return Disbursement::where('landlord_id', $user->id)->awaitingConfirmation()->count();
    }

    /** Disputed payouts across every landlord — the admin side's own to-do list for reconciliation. */
    public function disbursementsNeedingAttentionCount(): int
    {
        return Disbursement::disputed()->count();
    }

    /** Unread notifications for a landlord/manager — mirrors app/Livewire/NotificationBell.php. */
    public function unreadNotificationsCount(User $user): int
    {
        return AppNotification::forLandlordContext($user)->whereNull('read_at')->count();
    }

    /** Open/in-progress landlord support tickets — the B5 escalation workflow's own to-do list for admins. */
    public function openSupportTicketsCount(): int
    {
        return SupportTicket::open()->count();
    }

    /** Unread notifications addressed to this admin, same figure the admin bell shows. */
    public function adminUnreadNotificationsCount(User $admin): int
    {
        return AppNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $admin->id)
            ->whereNull('read_at')
            ->count();
    }

    /** Rent genuinely overdue on this tenant's own active tenancy. */
    public function tenantOverdueChargesCount(Tenant $tenant): int
    {
        return RentCharge::overdue()
            ->whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id)->active())
            ->count();
    }

    /** 1 when this tenant's deposit isn't fully paid, so the badge reads as "something to look at" rather than a confusing shilling amount. */
    public function tenantDepositOutstandingCount(Tenant $tenant): int
    {
        $tenancy = $tenant->currentTenancy();

        return $tenancy && $tenancy->depositOutstanding() > 0 ? 1 : 0;
    }

    /** Complaints this tenant themselves needs to confirm (or dispute) as actually resolved. */
    public function tenantAwaitingConfirmationCount(Tenant $tenant): int
    {
        return Complaint::where('tenant_id', $tenant->id)
            ->where('status', 'resolved')
            ->whereNull('tenant_confirmed_at')
            ->count();
    }

    /** 1 when this tenant has a notice they haven't yet acknowledged — the one thing that's always actionable on their side. */
    public function tenantTenancyNoticeActionCount(Tenant $tenant): int
    {
        return TenancyNotice::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id)->active())
            ->where('status', 'issued')
            ->count();
    }

    /**
     * A change request has no tenant-side action after submission (the
     * landlord decides, full stop), so there's nothing to badge there — only
     * a countered renewal proposal genuinely needs the tenant to act again.
     */
    public function tenantRenewalRequestActionCount(Tenant $tenant): int
    {
        return TenancyRenewalRequest::whereHas('tenancy', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where('status', 'alternative_proposed')
            ->count();
    }

    /** 1 when this tenant has a current-tenancy contract sent but not yet signed — the single most consequential pending tenant action. */
    public function tenantContractAwaitingSignatureCount(Tenant $tenant): int
    {
        $tenancy = $tenant->currentTenancy();
        $contract = $tenancy?->contract;

        return $contract && ! $contract->isSuperseded() && in_array($contract->status, ['sent', 'awaiting_signature'], true) ? 1 : 0;
    }

    /** Unread notifications for a tenant — mirrors Tenant\NotificationBell. */
    public function tenantUnreadNotificationsCount(Tenant $tenant): int
    {
        return AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)
            ->whereNull('read_at')
            ->count();
    }
}
