<?php

namespace Tests\Feature;

use App\Livewire\Admin\SupportTickets\Index as AdminSupportTickets;
use App\Livewire\Managers\Index as ManagersIndex;
use App\Livewire\Support\Index as SupportIndex;
use App\Models\AppNotification;
use App\Models\Manager;
use App\Models\MaintenanceTask;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers several audit findings together:
 * (B3) removing a manager hard-deleted their User row, degrading every
 * past activity_log entry and nulling maintenance_tasks.created_by for
 * everything they ever touched. Fixed by keeping the User row (already
 * permanently inactive) and deleting only the Manager delegation record.
 * (B4) a manager was never notified when their properties/permissions/
 * active-status changed — only found out via a 403 or a vanished sidebar
 * link.
 * (B5) there was no landlord → admin escalation path at all. Built as a
 * simple support-ticket workflow.
 * (B6) an admin changing an already-active landlord's status was silent.
 * (B7) complaints.resolve was a dead permission, checked nowhere.
 * (B8) Manager's own activity log excluded `permissions`, and the
 * manager_property pivot sync was never logged at all.
 * (B9) no admin notification existed for an overdue disbursement.
 */
class ManagerLifecycleAndEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions = [], bool $allProperties = true): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Lifecycle Manager {$n}",
            'phone' => '070011'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "lifecycle-manager-{$n}@example.test",
            'all_properties' => $allProperties,
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions($permissions);
        $manager->update(['user_id' => $managerUser->id]);

        return $managerUser->fresh();
    }

    public function test_removing_a_manager_keeps_their_user_row_so_attribution_survives(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord);
        $manager = $managerUser->manager;

        $property = $landlord->properties()->firstOrFail();
        $task = MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Fix fence',
            'category' => 'other',
            'priority' => 'low',
            'status' => 'submitted',
            'created_by' => $managerUser->id,
        ]);

        $manager->update(['status' => 'inactive']);
        $managerUser->update(['status' => 'inactive']);

        Livewire::actingAs($landlord)->test(ManagersIndex::class)->call('remove', $manager);

        $this->assertDatabaseMissing('managers', ['id' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $managerUser->id, 'status' => 'inactive']);
        $this->assertEquals($managerUser->id, $task->fresh()->created_by);
    }

    public function test_a_manager_is_notified_when_their_properties_or_permissions_change(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\Managers\Form::class, ['manager' => $managerUser->manager])
            ->set('permissions', ['tenants.view', 'tenants.edit'])
            ->call('save');

        $this->assertTrue(
            AppNotification::where('notifiable_type', $managerUser->getMorphClass())
                ->where('notifiable_id', $managerUser->id)
                ->where('event_type', 'manager_access_changed')
                ->exists()
        );
    }

    public function test_a_manager_is_notified_when_deactivated(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord);

        Livewire::actingAs($landlord)->test(ManagersIndex::class)->call('deactivate', $managerUser->manager);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $managerUser->getMorphClass())
                ->where('notifiable_id', $managerUser->id)
                ->where('event_type', 'manager_deactivated')
                ->exists()
        );
    }

    public function test_the_managers_own_bell_sees_a_notification_addressed_directly_to_them(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord);

        Livewire::actingAs($landlord)->test(ManagersIndex::class)->call('deactivate', $managerUser->manager);
        $managerUser->update(['status' => 'active']); // re-activate just to log back in and check their own bell

        $count = AppNotification::forLandlordContext($managerUser->fresh())->whereNull('read_at')->count();
        $this->assertGreaterThan(0, $count);
    }

    public function test_changing_an_already_active_landlords_status_notifies_them(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Landlords\Index::class)
            ->call('updateStatus', $landlord->id, 'suspended');

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'landlord_status_changed')
                ->exists()
        );
        $this->assertEquals('suspended', $landlord->fresh()->status);
    }

    public function test_re_saving_the_same_status_does_not_send_a_duplicate_notification(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'active']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Landlords\Index::class)
            ->call('updateStatus', $landlord->id, 'active');

        $this->assertFalse(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'landlord_status_changed')
                ->exists()
        );
    }

    public function test_complaints_resolve_permission_no_longer_exists(): void
    {
        $this->seed();

        $this->assertDatabaseMissing('permissions', ['name' => 'complaints.resolve']);
    }

    public function test_manager_property_access_change_is_logged_to_the_activity_log(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, [], allProperties: false);
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\Managers\Form::class, ['manager' => $managerUser->manager])
            ->set('all_properties', false)
            ->set('property_ids', [$property->id])
            ->call('save');

        $this->assertTrue(
            \Spatie\Activitylog\Models\Activity::where('subject_type', Manager::class)
                ->where('subject_id', $managerUser->manager->id)
                ->where('description', 'Manager property access changed')
                ->exists()
        );
    }

    public function test_the_full_escalation_workflow_landlord_opens_admin_replies_and_resolves(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SupportIndex::class)
            ->call('openNewForm')
            ->set('category', 'payment')
            ->set('subject', 'My payout is late')
            ->set('message', 'I was expecting a disbursement last week.')
            ->call('submit');

        $ticket = SupportTicket::firstOrFail();
        $this->assertEquals('open', $ticket->status);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'support_ticket_submitted')
                ->exists()
        );

        Livewire::actingAs($admin)
            ->test(AdminSupportTickets::class)
            ->call('toggleExpand', $ticket->id)
            ->set('replyBody', 'Looking into it now.')
            ->call('reply', $ticket->id);

        $this->assertEquals('in_progress', $ticket->fresh()->status);
        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'support_ticket_reply')
                ->exists()
        );

        Livewire::actingAs($admin)
            ->test(AdminSupportTickets::class)
            ->call('toggleExpand', $ticket->id)
            ->set('resolveNote', 'Disbursed today, sorry for the delay.')
            ->call('resolve', $ticket->id);

        $ticket->refresh();
        $this->assertEquals('resolved', $ticket->status);
        $this->assertEquals($admin->id, $ticket->resolved_by);
        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'support_ticket_resolved')
                ->exists()
        );
    }

    public function test_a_landlord_cannot_see_another_landlords_ticket(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');

        app(SupportTicketService::class)->create($otherLandlord, 'other', 'Not yours', 'Private message');

        $component = Livewire::actingAs($landlord)->test(SupportIndex::class);
        $this->assertCount(0, $component->viewData('tickets'));
    }

    public function test_an_admin_notification_fires_once_per_cycle_for_an_overdue_disbursement(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();

        $property = $landlord->properties()->firstOrFail();
        $house = \App\Models\House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->firstOrFail();
        $tenancy = \App\Models\Tenancy::where('house_id', $house->id)->first() ?? $this->createTenancyFallback($landlord, $house);

        \App\Models\Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 15000,
            'payment_date' => now()->subDays(20),
            'method' => 'mpesa',
            'collection_mode' => 'platform',
            'recorded_by' => $landlord->id,
            'source' => 'system_payment',
        ]);

        $this->travelTo((new DisbursementService)->mostRecentReleaseDate()->addDay());

        $this->artisan('notifications:disbursements-overdue')->assertExitCode(0);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'like', 'disbursement_overdue_%')
                ->exists()
        );

        $countBefore = AppNotification::where('event_type', 'like', 'disbursement_overdue_%')->count();
        $this->artisan('notifications:disbursements-overdue')->assertExitCode(0);
        $countAfter = AppNotification::where('event_type', 'like', 'disbursement_overdue_%')->count();

        $this->assertEquals($countBefore, $countAfter, 'A second run in the same cycle must not send a duplicate.');
    }

    private function createTenancyFallback(User $landlord, \App\Models\House $house): \App\Models\Tenancy
    {
        $tenant = \App\Models\Tenant::create([
            'name' => 'Disbursement Test Tenant',
            'phone' => '0700555444',
            'payment_due_day' => 1,
        ]);

        return app(\App\Services\TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }
}
