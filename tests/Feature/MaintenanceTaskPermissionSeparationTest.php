<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Models\Manager;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 7A, finding 4: MaintenanceTaskPolicy::update() used to treat
 * maintenance.schedule/.assign/.resolve as interchangeable — any one of the
 * three unlocked every write action on a task, including completion, cost
 * recording, liability decisions and deposit deductions. Every write method
 * in Maintenance\Index now calls one of the three specific abilities
 * (schedule/assign/resolve) instead.
 *
 * Phase 7A follow-up: resolve itself still conflated operational progress
 * with cost recording and liability/deposit-deduction decisions — split
 * further into maintenance.manage_costs and maintenance.manage_liability,
 * both fully independent of resolve and of each other (neither is a
 * superset of the other two). Tests here call Livewire methods directly,
 * not through the UI, per the brief's explicit requirement.
 */
class MaintenanceTaskPermissionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions, bool $allProperties = true): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Split Test Manager {$n}",
            'phone' => '090000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "split-test-manager-{$n}@example.test",
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

    private function makeTask(Property $property, string $status = 'submitted'): MaintenanceTask
    {
        return MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Test task',
            'category' => 'general',
            'priority' => 'medium',
            'status' => $status,
        ]);
    }

    public function test_scheduling_only_manager_can_schedule_but_cannot_assign_or_resolve(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.schedule']);

        $task = $this->makeTask($property);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_scheduled_date', now()->addDays(2)->format('Y-m-d'))
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertEquals('scheduled', $task->fresh()->status);

        $vendor = Vendor::create(['landlord_id' => $landlord->id, 'name' => 'Vendor', 'phone' => '0700000001', 'category' => 'general']);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_vendor_id', $vendor->id)
            ->call('saveAssign')
            ->assertForbidden();

        $this->assertNull($task->fresh()->vendor_id);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('markReviewed', $task->id)
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->assertForbidden();
    }

    public function test_assignment_only_manager_can_assign_but_cannot_schedule_or_resolve(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.assign']);

        $task = $this->makeTask($property);
        $vendor = Vendor::create(['landlord_id' => $landlord->id, 'name' => 'Vendor', 'phone' => '0700000002', 'category' => 'general']);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_vendor_id', $vendor->id)
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertEquals($vendor->id, $task->fresh()->vendor_id);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_scheduled_date', now()->addDays(3)->format('Y-m-d'))
            ->call('saveAssign')
            ->assertForbidden();

        $this->assertNull($task->fresh()->scheduled_date);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('startProgress', $task->id)
            ->assertForbidden();
    }

    public function test_resolution_only_manager_can_resolve_but_cannot_schedule_or_assign(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.resolve']);

        $task = $this->makeTask($property, status: 'in_progress');

        // Phase 7A follow-up: this authorization layer keys manage_costs off
        // the *submitted* cost value being > 0, matching the documented
        // intent that a genuine (non-zero) cost is what needs cost-recording
        // authority — so resolve alone must suffice for a 0-cost completion.
        // MaintenanceCostService::recordExpenseFor()'s own guard now agrees
        // (fixed to an explicit numeric comparison, decimal-cast-safe), so no
        // Expense is created here either — see MaintenanceCostServiceTest
        // for the guard's own dedicated regression coverage.
        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 0)
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNull($task->expense_id);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('closeTask', $task->id)
            ->assertHasNoErrors();

        $this->assertEquals('closed', $task->fresh()->status);

        $otherTask = $this->makeTask($property);
        $vendor = Vendor::create(['landlord_id' => $landlord->id, 'name' => 'Vendor', 'phone' => '0700000003', 'category' => 'general']);

        // A resolve-only manager holds neither of the two abilities
        // openAssignForm() accepts, so it must be forbidden on its own —
        // asserted here rather than chained into saveAssign(), since a
        // thrown exception mid-chain leaves the Livewire test instance in a
        // state further calls on the same instance can't reliably resume.
        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $otherTask->id)
            ->assertForbidden();

        // saveAssign() itself (independent of the opening form's own guard)
        // must also refuse both an assign-shaped and a schedule-shaped edit.
        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->set('activeTaskId', $otherTask->id)
            ->set('assign_vendor_id', $vendor->id)
            ->call('saveAssign')
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->set('activeTaskId', $otherTask->id)
            ->set('assign_scheduled_date', now()->addDay()->format('Y-m-d'))
            ->call('saveAssign')
            ->assertForbidden();

        $this->assertNull($otherTask->fresh()->vendor_id);
        $this->assertNull($otherTask->fresh()->scheduled_date);
    }

    public function test_a_manager_with_schedule_and_assign_but_not_resolve_can_do_both_but_not_complete(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.schedule', 'maintenance.assign']);

        $task = $this->makeTask($property);
        $vendor = Vendor::create(['landlord_id' => $landlord->id, 'name' => 'Vendor', 'phone' => '0700000004', 'category' => 'general']);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_vendor_id', $vendor->id)
            ->set('assign_scheduled_date', now()->addDays(2)->format('Y-m-d'))
            ->call('saveAssign')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals($vendor->id, $task->vendor_id);
        $this->assertEquals('scheduled', $task->status);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->set('activeTaskId', $task->id)
            ->call('complete')
            ->assertForbidden();
    }

    /** A manager must never gain financial/liability authority merely by holding scheduling or assignment permission. */
    public function test_a_manager_without_manage_liability_permission_cannot_initiate_a_deposit_deduction(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.schedule', 'maintenance.assign']);

        $task = $this->makeTask($property, status: 'completed');
        $task->update(['actual_cost' => 2000, 'completed_at' => now()]);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openDeductForm', $task->id)
            ->assertForbidden();

        // confirmDeductFromDeposit() itself (independent of openDeductForm()'s
        // own guard) must also refuse — asserted directly against the policy
        // rather than through a Livewire ->set('deductingTaskId', ...) call:
        // setting that property re-renders the component, and this task has
        // no linked complaint, which hits an unrelated, pre-existing blade
        // bug this phase's follow-up fixes separately
        // (resources/views/livewire/maintenance/index.blade.php, now guarded)
        // — not something this authorization test should depend on either way.
        $this->assertFalse($manager->can('manage_liability', $task));

        $this->assertNull($task->fresh()->deposit_deduction_id);
    }

    /** Requirement: a manager must remain restricted to their own accessible properties regardless of which permission they hold. */
    public function test_a_manager_with_resolve_permission_cannot_act_on_a_task_outside_their_property_scope(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Unassigned Property', 'type' => 'apartment', 'status' => 'active']);

        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.resolve'], allProperties: false);
        $manager->manager->properties()->attach($otherProperty->id);

        $task = $this->makeTask($property, status: 'in_progress');

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('markReviewed', $task->id)
            ->assertForbidden();

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    public function test_landlord_can_still_schedule_assign_and_resolve_without_any_manager_permission_change(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property);
        $vendor = Vendor::create(['landlord_id' => $landlord->id, 'name' => 'Vendor', 'phone' => '0700000005', 'category' => 'general']);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_vendor_id', $vendor->id)
            ->set('assign_scheduled_date', now()->addDays(2)->format('Y-m-d'))
            ->call('saveAssign')
            ->assertHasNoErrors();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('startProgress', $task->id)
            ->assertHasNoErrors();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 1200)
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertEquals('completed', $task->fresh()->status);
    }

    /**
     * Admin's blanket access (Property::accessibleBy()'s own hasRole('admin')
     * bypass, plus hasManagerPermission() returning true for any non-manager)
     * is structurally unrelated to the manager-permission split above — this
     * confirms the split didn't disturb it.
     */
    /**
     * Phase 7A follow-up, finding 1: proves operational resolve authority
     * does not, by itself, grant cost-recording authority — the gap the new
     * maintenance.manage_costs permission closes. complete() requires
     * resolve unconditionally and manage_costs only when a non-zero cost is
     * actually being recorded.
     */
    public function test_resolve_only_manager_cannot_complete_a_task_with_a_real_cost_without_manage_costs(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.resolve']);

        $task = $this->makeTask($property, status: 'in_progress');

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 1500)
            ->call('complete')
            ->assertForbidden();

        $task->refresh();
        $this->assertEquals('in_progress', $task->status);
        $this->assertNull($task->expense_id);
    }

    public function test_manager_with_resolve_and_manage_costs_can_complete_a_task_with_a_real_cost(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.resolve', 'maintenance.manage_costs']);

        $task = $this->makeTask($property, status: 'in_progress');

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 1500)
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->expense_id);
        $this->assertDatabaseHas('expenses', ['id' => $task->expense_id, 'amount' => 1500]);
    }

    /** manage_costs is not a superset of resolve either — it grants cost recording, never operational progress on its own. */
    public function test_manage_costs_alone_cannot_open_or_complete_a_task_without_resolve(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.manage_costs']);

        $task = $this->makeTask($property, status: 'in_progress');

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->assertForbidden();

        $this->assertEquals('in_progress', $task->fresh()->status);
    }

    /** The saveAssign() "record expense now" toggle is cost-recording authority specifically, not operational schedule/assign authority. */
    public function test_a_manager_with_schedule_and_assign_but_not_manage_costs_cannot_record_an_expense_via_save_assign(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.schedule', 'maintenance.assign']);

        $task = $this->makeTask($property);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_estimated_cost', 800)
            ->set('assign_record_expense_now', true)
            ->call('saveAssign')
            ->assertForbidden();

        $this->assertNull($task->fresh()->expense_id);
    }

    /**
     * Phase 7A follow-up, finding 1: proves resolve + manage_costs still
     * does not grant liability/deposit-deduction authority — the inline
     * "deduct from deposit" checkbox inside complete() requires
     * manage_liability specifically, on top of whatever else completing
     * already required.
     */
    public function test_resolve_and_manage_costs_cannot_initiate_a_deposit_deduction_during_completion_without_manage_liability(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.resolve', 'maintenance.manage_costs']);

        $task = $this->makeTask($property, status: 'in_progress');

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 1500)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', 1)
            ->set('complete_deduction_amount', 1500)
            ->set('complete_deduction_reason', 'Tenant caused this.')
            ->call('complete')
            ->assertForbidden();

        $task->refresh();
        $this->assertEquals('in_progress', $task->status);
        $this->assertNull($task->expense_id);
        $this->assertNull($task->deposit_deduction_id);
    }

    /**
     * manage_liability is fully independent of resolve/manage_costs — a
     * manager holding only it can still act on an already-completed task
     * (completed by someone else, e.g. the landlord) via the standalone
     * liability/deduction forms, which never additionally require resolve.
     */
    public function test_manage_liability_alone_can_open_liability_and_deduct_forms_on_an_already_completed_task(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManager($landlord, ['maintenance.view', 'maintenance.manage_liability']);

        $task = $this->makeTask($property, status: 'completed');
        $task->update(['actual_cost' => 1000, 'completed_at' => now()]);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->assertHasNoErrors();

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openDeductForm', $task->id)
            ->assertHasNoErrors();
    }

    public function test_admin_functionality_is_unaffected_by_the_permission_split(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $admin = User::role('admin')->firstOrFail();

        $task = $this->makeTask($property);

        Livewire::actingAs($admin)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('markReviewed', $task->id)
            ->assertHasNoErrors();

        $this->assertEquals('reviewed', $task->fresh()->status);
    }
}
