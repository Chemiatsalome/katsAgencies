<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a bug where AppNotification::actionUrl() decided
 * the destination route from who the notification was originally addressed
 * to ($this->notifiable), not from who's actually looking at it right now.
 * Those two routinely differ: a landlord/manager's bell deliberately also
 * shows notifications addressed to their own tenants (see
 * AppNotification::scopeForLandlordContext()), and the admin's bell/history
 * is a completely unscoped, platform-wide activity feed (see
 * Admin\NotificationBell / Admin\Notifications\Index). A tenant-only
 * notification like 'payment_confirmed' therefore surfaced in both the
 * landlord's and the admin's own feed, and clicking its "View" link sent
 * them to a /tenant/* route neither role can reach — a 403. Reproduced live
 * by the user: paying rent as a tenant, then clicking the resulting
 * notification from both the landlord and the admin account.
 */
class NotificationActionUrlViewerScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_addressed_payment_notification_has_no_link_for_a_landlord_viewing_it_in_their_own_bell(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'payment-scope-tenant@example.test', 'password'
        );

        $notification = AppNotification::create([
            'notifiable_type' => $tenancy->tenant->getMorphClass(),
            'notifiable_id' => $tenancy->tenant->id,
            'channel' => 'in_app',
            'event_type' => 'payment_confirmed',
            'title' => 'Payment Received',
            'body' => 'Your M-Pesa payment of KSh 10.00 (UJQQ2AM0G2) has been received.',
            'status' => 'sent',
        ]);

        // Sanity check: this is exactly the row the landlord's own bell
        // surfaces for this payment (scopeForLandlordContext includes every
        // notification addressed to a tenant they can access) — the bug
        // only manifests because this row genuinely does show up there.
        $this->assertTrue(
            AppNotification::forLandlordContext($landlord)->whereKey($notification->id)->exists(),
            'A notification addressed to the landlord\'s own tenant should surface in their notification feed.'
        );

        // The tenant it actually belongs to must still get a working link.
        $this->assertSame(route('tenant.payments'), $notification->actionUrl($tenantUser));

        // The landlord seeing the same row in their own bell must not be
        // handed that tenant-only route — degrades to "no link" instead.
        $this->assertNull($notification->actionUrl($landlord));

        // An admin's platform-wide feed shows the exact same row (no scope
        // at all) — same requirement applies.
        $admin = User::role('admin')->firstOrFail();
        $this->assertNull($notification->actionUrl($admin));
    }

    /**
     * Regression for a live bug report: a tenant's "ask us to look into
     * this" cancellation request notified the landlord, but
     * 'mpesa_cancellation_requested' had no case in actionUrl() at all —
     * the notification rendered with no link for anyone. related is the
     * MpesaTransaction itself (not a Tenancy, unlike mpesa_callback_discrepancy),
     * so this needs its own case deriving the tenancy via $this->related.
     *
     * Follow-up (landlord investigation workflow): a second live report
     * showed the landlord's "View" link landing on the general tenancy
     * overview with no indication of what to do — this now points at the
     * dedicated per-transaction investigation page instead.
     */
    public function test_a_cancellation_requested_notification_links_the_landlord_to_the_investigation_page_and_the_admin_to_the_payments_page(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-notif-link-1',
            'status' => 'pending',
            'cancellation_requested_at' => now(),
        ]);

        $notification = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(),
            'notifiable_id' => $landlord->id,
            'channel' => 'in_app',
            'event_type' => 'mpesa_cancellation_requested',
            'title' => 'A Tenant Requested Help With a Stuck M-Pesa Payment',
            'body' => 'A tenant asked us to look into an M-Pesa payment that never completed.',
            'status' => 'sent',
            'related_type' => $transaction->getMorphClass(),
            'related_id' => $transaction->id,
        ]);

        $this->assertSame(route('manage.mpesa-investigations.show', $transaction->id), $notification->actionUrl($landlord));

        $admin = User::role('admin')->firstOrFail();
        $this->assertSame(route('admin.payments.index'), $notification->actionUrl($admin));

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'cancel-link-tenant@example.test', 'password'
        );
        $this->assertNull($notification->actionUrl($tenantUser));
    }
}
