<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as LandlordMessages;
use App\Livewire\Tenant\Announcements\Index as TenantAnnouncements;
use App\Livewire\Tenant\Notifications\Index as TenantNotifications;
use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 8D (G-4): landlord_message notifications (announcements) used to be
 * mixed into the tenant's general Notifications inbox and its unread count
 * alongside every other notification type, even though the dedicated
 * Announcements page was always the intended place to read and reply to
 * them. Tenant\Notifications\Index::scopedQuery() now excludes
 * landlord_message entirely — these prove that exclusion without touching
 * the Announcements page, its reply flow, or any other notification type.
 */
class TenantNotificationAnnouncementSeparationTest extends TestCase
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

    public function test_landlord_message_notifications_are_excluded_from_every_general_inbox_filter(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Separation Test Announcement')
            ->set('body', 'This is an announcement, not a general notification.')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasNoErrors();

        app(NotificationService::class)->notify(
            $tenant, 'deposit_requested', 'Separation Test Deposit Notice', 'This is a plain notification.', ['in_app'],
        );

        // All filter: the announcement is gone, the plain notification remains (proof point 4).
        Livewire::actingAs($user)
            ->test(TenantNotifications::class)
            ->set('filter', 'all')
            ->assertDontSee('Separation Test Announcement')
            ->assertSee('Separation Test Deposit Notice');

        // Unread filter: same exclusion — both are currently unread.
        Livewire::actingAs($user)
            ->test(TenantNotifications::class)
            ->set('filter', 'unread')
            ->assertDontSee('Separation Test Announcement')
            ->assertSee('Separation Test Deposit Notice');

        // Read filter: mark both read directly, confirm the announcement
        // still never surfaces here even once read — this is a type
        // exclusion, not a state-dependent one.
        AppNotification::where('event_type', 'landlord_message')->update(['read_at' => now()]);
        AppNotification::where('event_type', 'deposit_requested')->update(['read_at' => now()]);

        Livewire::actingAs($user)
            ->test(TenantNotifications::class)
            ->set('filter', 'read')
            ->assertDontSee('Separation Test Announcement')
            ->assertSee('Separation Test Deposit Notice');
    }

    public function test_landlord_message_notifications_remain_visible_on_the_dedicated_announcements_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Still On Announcements')
            ->set('body', 'Excluding this from the general inbox must not hide it here.')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasNoErrors();

        Livewire::actingAs($user)
            ->test(TenantAnnouncements::class)
            ->assertSee('Still On Announcements');
    }

    public function test_general_inbox_unread_count_excludes_announcements(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Unread Count Announcement')
            ->set('body', 'Must not inflate the general inbox unread count.')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasNoErrors();

        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Unread Count A', 'First.', ['in_app']);
        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Unread Count B', 'Second.', ['in_app']);

        // Three unread notifications exist in total, but only the two
        // non-announcement ones belong in this inbox's count (proof point 4
        // again — the other types are still counted correctly).
        $this->assertEquals(
            3,
            AppNotification::where('notifiable_type', $tenant->getMorphClass())->where('notifiable_id', $tenant->id)->whereNull('read_at')->count(),
            'Sanity check: three unread rows actually exist.'
        );

        $unreadCount = Livewire::actingAs($user)->test(TenantNotifications::class)->viewData('unreadCount');
        $this->assertEquals(2, $unreadCount, 'The general inbox unread count must exclude the announcement.');
    }

    /**
     * markAllRead() is a bulk action on this page alone — it must not have
     * the side effect of silently marking an announcement "read" when the
     * tenant never actually viewed the Announcements page.
     */
    public function test_marking_all_read_in_the_general_inbox_does_not_touch_an_unread_announcement(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Must Stay Unread')
            ->set('body', 'markAllRead on the other page must not touch this.')
            ->set('channels', ['in_app'])
            ->call('send');

        app(NotificationService::class)->notify($tenant, 'deposit_requested', 'Will Be Marked Read', 'Plain notice.', ['in_app']);

        $announcement = AppNotification::where('event_type', 'landlord_message')->firstOrFail();
        $plainNotification = AppNotification::where('event_type', 'deposit_requested')->firstOrFail();

        Livewire::actingAs($user)->test(TenantNotifications::class)->call('markAllRead');

        $this->assertNull($announcement->fresh()->read_at, 'The announcement must remain unread until actually viewed on its own page.');
        $this->assertNotNull($plainNotification->fresh()->read_at, 'The plain notification must be marked read.');
    }
}
