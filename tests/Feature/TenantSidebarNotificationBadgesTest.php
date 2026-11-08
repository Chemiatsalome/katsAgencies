<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as LandlordMessages;
use App\Livewire\Tenant\AnnouncementsBadge;
use App\Livewire\Tenant\Announcements\Index as TenantAnnouncements;
use App\Livewire\Tenant\NotificationsBadge;
use App\Livewire\Tenant\Notifications\Index as TenantNotifications;
use App\Models\AppNotification;
use App\Models\House;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PendingActionsService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 8D follow-up: separate Notifications/Announcements sidebar badges,
 * refreshed live via a dispatched event rather than the stale, @persist'd
 * inline count the sidebar used before. PHPUnit can't simulate a real
 * browser's DOM morphing, so this proves the two halves of the mechanism
 * independently: that the action methods actually dispatch the event
 * (assertDispatched), and that each badge component actually recomputes
 * its count when it receives that event (->dispatch() on the testable,
 * which simulates an incoming Livewire event exactly as a browser-wide
 * dispatch from another component would deliver it).
 */
class TenantSidebarNotificationBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    private function createTenancyFor(User $landlord, string $tenantName, string $phone): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create(['name' => $tenantName, 'phone' => $phone, 'payment_due_day' => 1]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_general_notification_count_excludes_announcements(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;

        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Plain', 'Plain notice.', ['in_app']);
        AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 'Ann', 'body' => 'b', 'status' => 'sent',
        ]);

        $this->assertEquals(1, app(PendingActionsService::class)->tenantUnreadNotificationsCount($tenant));
    }

    public function test_announcement_count_includes_only_unread_announcements(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;

        AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 'Unread Ann', 'body' => 'b', 'status' => 'sent',
        ]);
        AppNotification::create([
            'notifiable_type' => $tenant->getMorphClass(), 'notifiable_id' => $tenant->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 'Read Ann', 'body' => 'b', 'status' => 'sent',
            'read_at' => now(),
        ]);
        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Plain', 'Plain notice.', ['in_app']);

        $this->assertEquals(1, app(PendingActionsService::class)->tenantUnreadAnnouncementsCount($tenant));
    }

    public function test_counts_are_isolated_between_tenants(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $tenancyA = $this->createTenancyFor($landlord, 'Badge Tenant A', '0700555666');
        $tenancyB = $this->createTenancyFor($landlord, 'Badge Tenant B', '0700555777');

        app(NotificationService::class)->notify($tenancyA->tenant, 'deposit_requested', 'For A', 'b', ['in_app']);
        AppNotification::create([
            'notifiable_type' => $tenancyA->tenant->getMorphClass(), 'notifiable_id' => $tenancyA->tenant->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 'Ann A', 'body' => 'b', 'status' => 'sent',
        ]);

        app(NotificationService::class)->notify($tenancyB->tenant, 'deposit_requested', 'For B 1', 'b', ['in_app']);
        app(NotificationService::class)->notify($tenancyB->tenant, 'deposit_requested', 'For B 2', 'b', ['in_app']);

        $pendingActions = app(PendingActionsService::class);

        $this->assertEquals(1, $pendingActions->tenantUnreadNotificationsCount($tenancyA->tenant));
        $this->assertEquals(1, $pendingActions->tenantUnreadAnnouncementsCount($tenancyA->tenant));
        $this->assertEquals(2, $pendingActions->tenantUnreadNotificationsCount($tenancyB->tenant));
        $this->assertEquals(0, $pendingActions->tenantUnreadAnnouncementsCount($tenancyB->tenant));
    }

    public function test_reading_a_general_notification_updates_the_notifications_badge(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;

        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Plain A', 'b', ['in_app']);
        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Plain B', 'b', ['in_app']);
        $toMarkRead = AppNotification::where('title', 'Plain A')->firstOrFail();

        // Initial badge state: 2 unread.
        Livewire::actingAs($user)->test(NotificationsBadge::class)->assertSeeHtml('2');

        // The inbox's markRead() must dispatch the refresh event.
        Livewire::actingAs($user)
            ->test(TenantNotifications::class)
            ->call('markRead', $toMarkRead->id)
            ->assertDispatched('tenant-notifications-updated');

        // Simulate the badge component receiving that dispatched event —
        // this is what a real browser session delivers automatically to
        // every listening component on the page, regardless of @persist.
        Livewire::actingAs($user)
            ->test(NotificationsBadge::class)
            ->dispatch('tenant-notifications-updated')
            ->assertSeeHtml('1');
    }

    public function test_reading_announcements_updates_the_announcements_badge(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Badge Announcement')
            ->set('body', 'b')
            ->set('channels', ['in_app'])
            ->call('send');

        Livewire::actingAs($user)->test(AnnouncementsBadge::class)->assertSeeHtml('1');

        // Viewing the Announcements page is what marks it read, and must
        // dispatch the refresh event exactly once (not once per render).
        Livewire::actingAs($user)
            ->test(TenantAnnouncements::class)
            ->assertDispatched('tenant-notifications-updated');

        Livewire::actingAs($user)
            ->test(AnnouncementsBadge::class)
            ->dispatch('tenant-notifications-updated')
            ->assertDontSeeHtml('1');
    }

    /** render() runs on every Livewire update, not just the first view — the dispatch must not fire again once nothing is left to mark read. */
    public function test_viewing_announcements_again_does_not_redundantly_dispatch_once_already_read(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')->set('tenant_id', $tenant->id)
            ->set('subject', 'Already Read')->set('body', 'b')
            ->call('send');

        // First mount marks it read and dispatches.
        $component = Livewire::actingAs($user)->test(TenantAnnouncements::class);
        $component->assertDispatched('tenant-notifications-updated');

        // Force another render (e.g. toggling a sibling announcement's
        // expand state) — nothing left unread, so no further dispatch.
        $component->call('toggleExpand', AppNotification::where('event_type', 'landlord_message')->firstOrFail()->id);
        $component->assertNotDispatched('tenant-notifications-updated');
    }

    /** Existing behaviour: markAllRead() on the general inbox must not touch an unread announcement or its badge. */
    public function test_mark_all_read_does_not_affect_the_announcements_badge(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')->set('tenant_id', $tenant->id)
            ->set('subject', 'Untouched Announcement')->set('body', 'b')
            ->call('send');

        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Plain', 'b', ['in_app']);

        Livewire::actingAs($user)->test(TenantNotifications::class)->call('markAllRead');

        $this->assertEquals(1, app(PendingActionsService::class)->tenantUnreadAnnouncementsCount($tenant));
        $this->assertEquals(0, app(PendingActionsService::class)->tenantUnreadNotificationsCount($tenant));
    }
}
