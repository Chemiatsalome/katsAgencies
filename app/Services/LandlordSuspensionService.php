<?php

namespace App\Services;

use App\Models\Manager;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;

/**
 * Phase 4 (account lifecycle): what happens to a landlord's managers and
 * tenants when an admin suspends or reinstates them — called only from
 * Admin\Landlords\Index::updateStatus(), the sole admin-facing landlord
 * suspension action, on an actual status transition.
 *
 * Deliberately asymmetric by design, not an oversight:
 * - Managers are 1:1 with a landlord (enforced at invitation/acceptance —
 *   see Managers\Form::emailRules()/ManagerInvitations\Accept::register()),
 *   so their User.status is unambiguously this landlord's to control.
 *   suspended_via_landlord_suspension_at records that the restriction is
 *   inherited, not an independent landlord decision, so reinstate() only
 *   restores managers it itself suspended.
 * - A tenant's User.status is never touched here at all. One Tenant
 *   business record can only ever resolve to the tenancies under the
 *   properties it actually has, but User::tenant() is a plain hasOne with
 *   no way to represent "this login has tenancies under several
 *   landlords" — the schema doesn't prevent tenants.user_id being shared
 *   across Tenant rows, but nothing in the app could then tell which one a
 *   shared login should see. Suspending the tenant's login globally would
 *   risk locking them out of an unrelated, unaffected landlord relationship
 *   the moment multi-landlord tenants exist. Restricting access instead
 *   happens live, per request, wherever a tenant would otherwise act on
 *   this specific landlord's services (PaymentGatewayService::
 *   initiateStkPush(), and the tenant-initiated actions gated by
 *   Tenant::currentTenancyLandlordIsSuspended()) — nothing to "restore"
 *   on reinstatement because nothing was ever revoked.
 */
class LandlordSuspensionService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function suspend(User $landlord, string $reason = ''): void
    {
        // The platform's own configured administrator contact (set via
        // Admin\Settings, the same source EnsureAccountIsActive and
        // LoginForm::deactivatedMessage() already use for an analogous
        // "contact your administrator" line) — never a second, duplicate
        // address.
        $contact = Setting::get('org_contact_email');
        $contactLine = $contact ? " For questions about this restriction, contact Kats Agencies administration at {$contact}." : '';
        $reasonLine = $reason !== '' ? " Reason given: {$reason}." : '';

        Manager::where('landlord_id', $landlord->id)->where('status', 'active')->get()
            ->each(function (Manager $manager) use ($landlord, $reasonLine, $contactLine) {
                $manager->pendingActionReason = 'Inherited: the landlord\'s own Kats Agencies account was suspended by an administrator.';
                $manager->update(['status' => 'inactive', 'suspended_via_landlord_suspension_at' => now()]);
                $manager->user?->update(['status' => 'inactive']);

                if ($manager->user) {
                    $this->notifications->notify(
                        $manager->user,
                        'manager_access_suspended_via_landlord',
                        'Your Access Has Been Suspended',
                        "Your access has been suspended because {$landlord->name}'s Kats Agencies account was suspended by Kats Agencies administration. This is not an independent action against you — it was not your own account that was suspended, and access will be restored automatically if the landlord's account is reinstated.{$reasonLine}{$contactLine}",
                        ['in_app', 'email'],
                        $manager,
                    );
                }
            });

        $this->affectedTenants($landlord)->each(fn (Tenant $tenant) => $this->notifications->notify(
            $tenant,
            'landlord_suspended',
            "Your Landlord's Account Has Been Suspended",
            "Your landlord's Kats Agencies account has been suspended. Access to the tenant portal and platform payment functionality is temporarily unavailable for this property while this restriction is in place. This does not terminate your tenancy, and it does not cancel your rent or any other obligations under your tenancy agreement. Please contact your landlord through your existing communication channels for instructions on how to make payments while this platform restriction remains in place.{$contactLine}",
            ['in_app', 'email'],
            $landlord,
        ));
    }

    public function reinstate(User $landlord): void
    {
        Manager::where('landlord_id', $landlord->id)->whereNotNull('suspended_via_landlord_suspension_at')->get()
            ->each(function (Manager $manager) use ($landlord) {
                $manager->pendingActionReason = 'Restored: the landlord\'s Kats Agencies account was reinstated by an administrator.';
                $manager->update(['status' => 'active', 'suspended_via_landlord_suspension_at' => null]);
                $manager->user?->update(['status' => 'active']);

                if ($manager->user) {
                    $this->notifications->notify(
                        $manager->user,
                        'manager_access_restored_via_landlord',
                        'Your Access Has Been Restored',
                        "Your access has been restored — {$landlord->name}'s Kats Agencies account has been reinstated by Kats Agencies administration.",
                        ['in_app', 'email'],
                        $manager,
                    );
                }
            });

        $this->affectedTenants($landlord)->each(fn (Tenant $tenant) => $this->notifications->notify(
            $tenant,
            'landlord_reinstated',
            "Your Landlord's Account Has Been Reinstated",
            "Your landlord's Kats Agencies account has been reinstated. The tenant portal and platform payment functionality for this property are available again.",
            ['in_app', 'email'],
            $landlord,
        ));
    }

    /** Every tenant with a currently-occupying tenancy under this landlord. */
    private function affectedTenants(User $landlord): \Illuminate\Support\Collection
    {
        return Tenant::whereHas(
            'tenancies',
            fn ($q) => $q->active()->whereHas('house.block.property', fn ($q2) => $q2->where('landlord_id', $landlord->id))
        )->get();
    }
}
