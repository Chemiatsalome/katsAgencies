<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Maintenance\Schedules\Form as ScheduleForm;
use App\Livewire\Maintenance\Schedules\Index as SchedulesIndex;
use App\Models\Complaint;
use App\Models\Manager;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordMaintenanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions, bool $allProperties = true): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Test Manager {$n}",
            'phone' => '080000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "maintenance-test-manager-{$n}@example.test",
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

    public function test_landlord_can_log_a_maintenance_task_manually(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openTaskForm')
            ->set('task_property_id', $property->id)
            ->set('task_title', 'Fix leaking roof')
            ->set('task_category', 'roofing')
            ->set('task_priority', 'high')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('maintenance_tasks', [
            'property_id' => $property->id,
            'title' => 'Fix leaking roof',
            'status' => 'submitted',
        ]);
    }

    /**
     * Phase 7B, finding 4: createTask() never notified anyone at all,
     * including for a task logged at emergency priority directly against an
     * occupied house — the tenant living there had no way to find out their
     * landlord already knew. Scoped to emergency + a specific house only;
     * verified via both channels, with no duplicate rows.
     *
     * This test explicitly sets tenants.email, which seeded tenants do not
     * have by default — NotificationService::deliverEmail() reads the
     * notifiable's own ->email attribute directly, and for a Tenant that is
     * tenants.email specifically, not tenants.user.email (the separate
     * portal-login account's email). The two are never kept in sync
     * anywhere in the app (confirmed: TenancyProvisioningService::
     * createPortalAccount() creates the User but never back-fills
     * tenants.email). This is a genuine, pre-existing delivery gap — a
     * tenant with working portal access but no separately-entered contact
     * email silently never receives ANY email-channel notification —
     * reported separately rather than fixed here, since it's well outside
     * this phase's scope. Isolating it here so this test proves this
     * phase's own notification-triggering logic, not that unrelated gap.
     */
    public function test_landlord_logging_an_emergency_task_for_an_occupied_house_notifies_the_active_tenant(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = \App\Models\Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->with('house.block.property', 'tenant')
            ->firstOrFail();
        $tenancy->tenant->update(['email' => 'emergency-notify-tenant@example.test']);
        $property = $tenancy->house->block->property;

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openTaskForm')
            ->set('task_property_id', $property->id)
            ->set('task_house_id', $tenancy->house_id)
            ->set('task_title', 'Gas leak reported by neighbour')
            ->set('task_category', 'gas')
            ->set('task_priority', 'emergency')
            ->call('createTask')
            ->assertHasNoErrors();

        $notifications = \App\Models\AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->where('notifiable_id', $tenancy->tenant_id)
            ->where('event_type', 'maintenance_status_submitted')
            ->get();

        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $notifications->pluck('channel')->all());
        $this->assertTrue($notifications->every(fn ($n) => $n->status === 'sent'));

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\GenericNotificationMail::class, 1);
    }

    /** Regression: a non-emergency, landlord-created task must keep its original, unchanged behavior — no tenant notification at all. */
    public function test_landlord_logging_a_non_emergency_task_for_an_occupied_house_does_not_notify_the_tenant(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = \App\Models\Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->with('house.block.property', 'tenant')
            ->firstOrFail();
        $property = $tenancy->house->block->property;

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openTaskForm')
            ->set('task_property_id', $property->id)
            ->set('task_house_id', $tenancy->house_id)
            ->set('task_title', 'Routine gutter cleaning')
            ->set('task_category', 'general')
            ->set('task_priority', 'medium')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertEquals(0, \App\Models\AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->where('notifiable_id', $tenancy->tenant_id)->count());

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    /** An emergency task logged property-wide (no specific house) has no single tenant to notify — must not error. */
    public function test_landlord_logging_an_emergency_task_with_no_house_does_not_error_or_notify_anyone(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openTaskForm')
            ->set('task_property_id', $property->id)
            ->set('task_title', 'Main water supply emergency')
            ->set('task_category', 'water')
            ->set('task_priority', 'emergency')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('maintenance_tasks', ['title' => 'Main water supply emergency', 'priority' => 'emergency']);
        $this->assertEquals(0, \App\Models\AppNotification::where('event_type', 'maintenance_status_submitted')->count());
    }

    public function test_a_tenant_complaint_can_be_converted_to_a_maintenance_task(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $complaint = Complaint::create([
            'property_id' => $property->id,
            'category' => 'plumbing',
            'subject' => 'Leaking tap',
            'description' => 'Kitchen tap has been leaking for a week.',
            'priority' => 'medium',
            'status' => 'submitted',
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('convertToTask', $complaint->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('maintenance_tasks', [
            'complaint_id' => $complaint->id,
            'title' => 'Leaking tap',
            'property_id' => $property->id,
        ]);

        $this->assertEquals('assigned', $complaint->fresh()->status);
    }

    public function test_completing_a_task_records_an_expense_exactly_once_even_if_triggered_twice(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Repaint corridor',
            'category' => 'painting',
            'priority' => 'low',
            'status' => 'in_progress',
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 3500)
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->expense_id);
        $this->assertDatabaseHas('expenses', ['id' => $task->expense_id, 'amount' => 3500]);
        $this->assertEquals(1, \App\Models\Expense::where('property_id', $property->id)->where('amount', 3500)->count());

        // Simulate the "mark completed" action firing a second time — must not duplicate the expense.
        app(\App\Services\MaintenanceCostService::class)->recordExpenseFor($task->fresh());
        $this->assertEquals(1, \App\Models\Expense::where('property_id', $property->id)->where('amount', 3500)->count());
    }

    public function test_landlord_can_assign_a_vendor_and_schedule_a_task(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $vendor = Vendor::create([
            'landlord_id' => $landlord->id, 'name' => 'Joe Plumber', 'phone' => '0700123456', 'category' => 'plumbing',
        ]);

        $task = MaintenanceTask::create([
            'property_id' => $property->id, 'title' => 'Fix pipe', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'reviewed',
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_vendor_id', $vendor->id)
            ->set('assign_scheduled_date', now()->addDays(2)->format('Y-m-d'))
            ->call('saveAssign')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals($vendor->id, $task->vendor_id);
        $this->assertEquals('scheduled', $task->status);
    }

    public function test_landlord_can_create_a_vendor(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'vendors'])
            ->call('openVendorForm')
            ->set('vendor_name', 'ACME Electrics')
            ->set('vendor_phone', '0711000111')
            ->set('vendor_category', 'electrical')
            ->call('saveVendor')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vendors', ['landlord_id' => $landlord->id, 'name' => 'ACME Electrics']);
    }

    public function test_landlord_can_create_a_preventive_maintenance_schedule(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(ScheduleForm::class)
            ->set('property_id', $property->id)
            ->set('title', 'Water Tank Cleaning')
            ->set('frequency_months', 12)
            ->set('next_due_date', now()->addMonths(12)->format('Y-m-d'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('maintenance_schedules', [
            'property_id' => $property->id,
            'title' => 'Water Tank Cleaning',
        ]);

        Livewire::actingAs($landlord)
            ->test(SchedulesIndex::class)
            ->assertSee('Water Tank Cleaning');
    }

    public function test_emergency_priority_tasks_sort_first_not_last(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        foreach (['low', 'medium', 'high', 'emergency'] as $priority) {
            MaintenanceTask::create([
                'property_id' => $property->id,
                'title' => ucfirst($priority).' task',
                'category' => 'general',
                'priority' => $priority,
                'status' => 'submitted',
            ]);
        }

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled']);
        $tasks = $component->instance()->render()->getData()['tasks'];

        // Alphabetical descending on the raw string would put "medium" first
        // and "emergency" last — urgency order must put emergency first.
        $this->assertEquals('emergency', $tasks->first()->priority);
        $this->assertEquals('low', $tasks->last()->priority);
    }

    public function test_starting_progress_on_an_already_completed_task_is_a_no_op(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Repaint corridor',
            'category' => 'painting',
            'priority' => 'low',
            'status' => 'completed',
            'actual_cost' => 3500,
            'completed_at' => now(),
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('startProgress', $task->id);

        $this->assertEquals('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_recurring_maintenance_schedules_are_registered_on_the_daily_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('maintenance:generate-recurring-tasks')
            ->assertExitCode(0);
    }

    /**
     * Phase 7A, F2: an appliance complaint must reach the landlord's
     * Requests tab exactly like any pre-existing category, and every
     * pre-existing category must keep working after the enum widening.
     */
    public function test_landlord_sees_an_appliance_complaint_alongside_existing_categories(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Complaint::create([
            'property_id' => $property->id, 'category' => 'appliance', 'subject' => 'Fridge not cooling',
            'description' => 'The fridge stopped cooling overnight.', 'priority' => 'medium', 'status' => 'submitted',
        ]);
        Complaint::create([
            'property_id' => $property->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.', 'priority' => 'medium', 'status' => 'submitted',
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->assertSee('Fridge not cooling')
            ->assertSee('Leaking tap');

        $this->assertDatabaseHas('complaints', ['subject' => 'Fridge not cooling', 'category' => 'appliance']);
        $this->assertDatabaseHas('complaints', ['subject' => 'Leaking tap', 'category' => 'plumbing']);
    }

    public function test_a_landlord_cannot_convert_another_landlords_complaint(): void
    {
        $this->seed();

        $actingLandlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);
        $otherProperty = Property::create([
            'landlord_id' => $otherLandlord->id, 'name' => 'Other Property', 'type' => 'apartment',
            'address' => '1 Elsewhere Rd', 'status' => 'active',
        ]);

        $complaint = Complaint::create([
            'property_id' => $otherProperty->id, 'category' => 'electrical', 'subject' => 'Power outage',
            'description' => 'No power in unit 2.', 'priority' => 'high', 'status' => 'submitted',
        ]);

        Livewire::actingAs($actingLandlord)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('convertToTask', $complaint->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenance_tasks', ['complaint_id' => $complaint->id]);
    }

    /**
     * Phase 7A, F9: convertToTask() only ever checked ComplaintPolicy::update()
     * (complaints.respond) — a manager granted that but not maintenance.create
     * could still create MaintenanceTask rows through this one path, bypassing
     * the permission the landlord specifically withheld.
     */
    public function test_a_manager_with_complaints_respond_and_maintenance_create_can_convert_a_complaint(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['complaints.view', 'complaints.respond', 'maintenance.create']);

        $complaint = Complaint::create([
            'property_id' => $property->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.', 'priority' => 'medium', 'status' => 'submitted',
        ]);

        Livewire::actingAs($managerUser)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('convertToTask', $complaint->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('maintenance_tasks', ['complaint_id' => $complaint->id]);
        $this->assertEquals('assigned', $complaint->fresh()->status);
    }

    public function test_a_manager_with_complaints_respond_but_without_maintenance_create_cannot_convert_a_complaint(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['complaints.view', 'complaints.respond']);

        $complaint = Complaint::create([
            'property_id' => $property->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.', 'priority' => 'medium', 'status' => 'submitted',
        ]);

        Livewire::actingAs($managerUser)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('convertToTask', $complaint->id)
            ->assertForbidden();

        // Unauthorized attempts must not create a task or partially modify the complaint.
        $this->assertDatabaseMissing('maintenance_tasks', ['complaint_id' => $complaint->id]);
        $this->assertEquals('submitted', $complaint->fresh()->status);
    }

    public function test_a_manager_without_access_to_the_property_cannot_convert_its_complaint_even_with_both_permissions(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        // Scoped to no properties at all (all_properties: false, nothing attached).
        $managerUser = $this->makeManager($landlord, ['complaints.view', 'complaints.respond', 'maintenance.create'], allProperties: false);

        $complaint = Complaint::create([
            'property_id' => $property->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.', 'priority' => 'medium', 'status' => 'submitted',
        ]);

        Livewire::actingAs($managerUser)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('convertToTask', $complaint->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenance_tasks', ['complaint_id' => $complaint->id]);
        $this->assertEquals('submitted', $complaint->fresh()->status);
    }

    /**
     * Phase 7A follow-up, finding 2: the "Deduct from Deposit" modal read
     * $deductingTask->complaint->tenant_confirmed_at with no null-safe
     * operator — for a landlord-created task with no linked complaint
     * (complaint_id null, which MaintenanceCostService explicitly permits
     * deducting from), opening the modal threw "Attempt to read property
     * tenant_confirmed_at on null" instead of rendering.
     */
    public function test_opening_the_deduct_form_does_not_throw_for_a_landlord_created_task_with_no_linked_complaint(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Repaint corridor',
            'category' => 'painting',
            'priority' => 'low',
            'status' => 'completed',
            'actual_cost' => 3500,
            'completed_at' => now(),
        ]);

        $this->assertNull($task->complaint_id);

        $html = Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openDeductForm', $task->id)
            ->assertHasNoErrors()
            ->html();

        $this->assertStringContainsString('logged directly, with no linked tenant complaint', $html);
    }
}
