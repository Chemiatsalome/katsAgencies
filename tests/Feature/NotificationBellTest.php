<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Models\Manager;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_dashboard_view_all_link_points_to_the_landlord_notifications_route(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $this->actingAs($landlord)
            ->get(route('landlord.dashboard'))
            ->assertOk()
            ->assertSee(route('manage.notifications.index'), false)
            ->assertDontSee(route('admin.notifications.index'), false);
    }

    public function test_manager_dashboard_view_all_link_points_to_the_manage_notifications_route(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $managerUser = User::factory()->create(['status' => 'active']);
        $managerUser->assignRole('manager');
        Manager::create([
            'landlord_id' => $landlord->id,
            'user_id' => $managerUser->id,
            'name' => $managerUser->name,
            'phone' => '0700000000',
            'email' => $managerUser->email,
            'all_properties' => true,
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'activated_at' => now(),
        ]);

        $this->actingAs($managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk()
            ->assertSee(route('manage.notifications.index'), false)
            ->assertDontSee(route('admin.notifications.index'), false);
    }

    public function test_admin_dashboard_view_all_link_still_points_to_the_admin_notifications_route(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.notifications.index'), false);
    }

    public function test_tenant_dashboard_view_all_link_points_to_the_tenant_notifications_route(): void
    {
        $this->seed();

        $tenantUser = User::role('tenant')->firstOrFail();

        $this->actingAs($tenantUser)
            ->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee(route('tenant.notifications.index'), false);
    }

    public function test_landlord_notification_bell_only_counts_this_landlords_tenant_notifications(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenancy->tenant_id)
            ->set('subject', 'Bell Test Notice')
            ->set('body', 'Body text')
            ->set('channels', ['in_app'])
            ->call('send');

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\NotificationBell::class)
            ->assertSee('Bell Test Notice');
    }

    public function test_a_notification_addressed_to_the_landlord_themself_is_visible_in_their_bell_and_history(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        // Mirrors what Tenant/Maintenance/Create.php does when a tenant
        // files a complaint — notifiable is the landlord's User row, not a
        // Tenant, which previously meant it never showed up anywhere for
        // the landlord outside the platform-wide admin view.
        app(NotificationService::class)->notify(
            $landlord,
            'complaint_submitted',
            'New Maintenance Complaint',
            'A tenant submitted a complaint about a leaking tap.',
            ['in_app'],
        );

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\NotificationBell::class)
            ->assertSee('New Maintenance Complaint');

        Livewire::actingAs($landlord)
            ->test(NotificationsIndex::class)
            ->assertSee('New Maintenance Complaint')
            ->assertSee('You');
    }

    public function test_a_delegated_manager_sees_notifications_addressed_to_their_landlord(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $managerUser = User::factory()->create(['status' => 'active']);
        $managerUser->assignRole('manager');
        Manager::create([
            'landlord_id' => $landlord->id,
            'user_id' => $managerUser->id,
            'name' => $managerUser->name,
            'phone' => '0700000001',
            'email' => $managerUser->email,
            'all_properties' => true,
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'activated_at' => now(),
        ]);

        app(NotificationService::class)->notify(
            $landlord,
            'complaint_submitted',
            'New Maintenance Complaint For Manager',
            'A tenant submitted a complaint.',
            ['in_app'],
        );

        Livewire::actingAs($managerUser)
            ->test(\App\Livewire\NotificationBell::class)
            ->assertSee('New Maintenance Complaint For Manager');
    }

    public function test_a_landlord_does_not_see_notifications_addressed_to_another_landlord(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        Property::create([
            'landlord_id' => $otherLandlord->id, 'name' => 'Other Landlord Property', 'type' => 'apartment', 'status' => 'active',
        ]);

        app(NotificationService::class)->notify(
            $otherLandlord,
            'complaint_submitted',
            'Notification For Other Landlord Only',
            'Should not leak.',
            ['in_app'],
        );

        Livewire::actingAs($landlord)
            ->test(NotificationsIndex::class)
            ->assertDontSee('Notification For Other Landlord Only');
    }
}
