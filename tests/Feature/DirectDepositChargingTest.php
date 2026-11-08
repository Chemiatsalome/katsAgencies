<?php

namespace Tests\Feature;

use App\Livewire\Expenses\Form as ExpensesForm;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Tenant\Deposit as TenantDeposit;
use App\Models\Block;
use App\Models\Complaint;
use App\Models\DepositDeduction;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepositService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct maintenance/expense deposit charging: replaces the disconnected
 * two-step liability-then-deduction workflow with a single "who bears this
 * cost?" toggle on both the Maintenance completion form and the Expense
 * creation form. A new deduction created through either toggle starts
 * "awaiting tenant response" — it does not count against the held balance
 * until the tenant acknowledges it, or a dispute is resolved in the
 * landlord's favour — reusing the existing DepositDeduction dispute
 * mechanism rather than a second one. Confirming maintenance completion and
 * disputing financial liability are kept as two separate questions.
 */
class DirectDepositChargingTest extends TestCase
{
    use RefreshDatabase;

    private function houseWithTenancy(User $landlord, float $depositPaid = 15000): array
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Charging Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'CH-'.str()->random(6),
            'monthly_rent' => 15000, 'deposit_amount' => 15000, 'status' => 'occupied',
        ]);
        $tenant = Tenant::create(['name' => 'Charging Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $tenancy = Tenancy::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonths(6)->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => $depositPaid,
            'status' => 'active',
        ]);

        return [$house, $tenancy];
    }

    private function inProgressTask(House $house, Tenancy $tenancy): MaintenanceTask
    {
        return MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'tenancy_id' => $tenancy->id, 'title' => 'Broken door', 'category' => 'repairs',
            'priority' => 'medium', 'status' => 'in_progress',
        ]);
    }

    // --- Maintenance: tenant charging ------------------------------------------------

    public function test_maintenance_completion_charged_to_tenant_proposes_a_deduction_awaiting_response(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'Tenant broke the door.')
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->expense_id);
        $this->assertNotNull($task->deposit_deduction_id);

        $deduction = DepositDeduction::find($task->deposit_deduction_id);
        $this->assertTrue($deduction->isAwaitingTenantResponse());
        $this->assertEquals(4000, (float) $deduction->amount);

        // Not yet counted — the whole point of "awaiting response."
        $this->assertEquals(15000.0, $tenancy->fresh()->depositHeldBalance());
    }

    /** "If No, record the maintenance expense normally without deducting from the deposit." */
    public function test_maintenance_completion_with_landlord_responsibility_records_a_normal_expense(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'landlord')
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->expense_id);
        $this->assertNull($task->deposit_deduction_id);
        $this->assertEquals(0, DepositDeduction::where('tenancy_id', $tenancy->id)->count());
        $this->assertEquals(15000.0, $tenancy->fresh()->depositHeldBalance());
    }

    /** Confirming maintenance completion and disputing financial liability are not the same gate — a complaint-linked task can never be charged at completion time regardless of what the tenant later confirms. */
    public function test_maintenance_completion_toggle_is_unavailable_for_a_complaint_linked_task(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);

        $complaint = Complaint::create([
            'house_id' => $house->id, 'tenant_id' => $tenancy->tenant_id,
            'category' => 'plumbing', 'subject' => 'Leak', 'description' => 'Leaking pipe',
            'priority' => 'medium', 'status' => 'assigned',
        ]);
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'complaint_id' => $complaint->id, 'title' => 'Fix leak', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'in_progress',
        ]);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'Attempting to charge before confirmation.')
            ->call('complete')
            ->assertHasNoErrors();

        // The task still completes normally — the toggle is simply never honoured for this case.
        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertNull($task->deposit_deduction_id);
        $this->assertEquals(0, DepositDeduction::where('tenancy_id', $tenancy->id)->count());
    }

    /** Once confirmed, charging is available directly from the Work Orders list for that same task. */
    public function test_once_tenant_confirms_a_complaint_linked_task_can_be_charged_directly(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);

        $complaint = Complaint::create([
            'house_id' => $house->id, 'tenant_id' => $tenancy->tenant_id,
            'category' => 'plumbing', 'subject' => 'Leak', 'description' => 'Leaking pipe',
            'priority' => 'medium', 'status' => 'resolved', 'tenant_confirmed_at' => now(),
        ]);
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'complaint_id' => $complaint->id, 'title' => 'Fix leak', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => 3000, 'completed_at' => now(),
        ]);
        app(\App\Services\MaintenanceCostService::class)->recordExpenseFor($task);
        $task->refresh();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('liability_reason', 'Tenant caused the leak.')
            ->call('confirmTenantLiability')
            ->assertHasNoErrors();

        $deduction = DepositDeduction::where('tenancy_id', $tenancy->id)->first();
        $this->assertNotNull($deduction);
        $this->assertTrue($deduction->isAwaitingTenantResponse());
    }

    // --- Partial deduction & insufficient balance ------------------------------------

    public function test_partial_deduction_is_supported_when_cost_exceeds_held_balance(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord, depositPaid: 3000);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 8000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 3000) // only what's held
            ->set('complete_deduction_reason', 'Partial coverage.')
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $deduction = DepositDeduction::find($task->deposit_deduction_id);
        $this->assertEquals(3000, (float) $deduction->amount);
        $this->assertEquals(8000, (float) $task->expense->amount);
        // Unrecovered amount is derivable, not separately stored: expense amount − deduction amount.
        $this->assertEquals(5000, (float) $task->expense->amount - (float) $deduction->amount);
    }

    public function test_deduction_amount_cannot_exceed_the_task_actual_cost(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord, depositPaid: 15000);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 9000) // more than the cost itself
            ->set('complete_deduction_reason', 'Trying to over-charge.')
            ->call('complete');

        $task->refresh();
        // The task still completes (expense recorded) but the over-amount charge is refused, not silently capped.
        $this->assertEquals('completed', $task->status);
        $this->assertNull($task->deposit_deduction_id);
        $this->assertEquals(0, DepositDeduction::where('tenancy_id', $tenancy->id)->count());
    }

    public function test_insufficient_deposit_balance_refuses_the_whole_charge_without_leaving_the_expense_marked(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord, depositPaid: 0);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'No deposit held.')
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        // The expense is still recorded — only the charge failed, atomically.
        $this->assertEquals('completed', $task->status);
        $this->assertNotNull($task->expense_id);
        $this->assertNull($task->deposit_deduction_id);
        $this->assertEquals(0, DepositDeduction::where('tenancy_id', $tenancy->id)->count());

        // No stray liability decision left behind either — rolled back together.
        $this->assertDatabaseMissing('tenant_liability_decisions', [
            'decidable_type' => Expense::class, 'decidable_id' => $task->fresh()->expense_id,
        ]);
    }

    /**
     * Post-implementation defect fix: a failed charge-to-tenant proposal at
     * completion time used to leave the real error silently dropped (never
     * rendered anywhere) while an unconditional "awaiting the tenant's
     * response" success flash was shown regardless of the failure. Now only
     * the real error is flashed, and it must actually render on the page.
     */
    public function test_maintenance_completion_failure_shows_the_real_error_not_the_optimistic_status(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord, depositPaid: 0);
        $task = $this->inProgressTask($house, $tenancy);

        $component = Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'No deposit held.')
            ->call('complete');

        // The real failure reason must actually render on the page — not be
        // silently dropped — and the optimistic success message must not
        // appear alongside a real failure.
        $component->assertSee("wasn't recorded");
        $component->assertDontSee("awaiting the tenant's response");

        // The task itself is still correctly completed — only the proposal failed.
        $this->assertEquals('completed', $task->fresh()->status);
        $this->assertNull($task->fresh()->deposit_deduction_id);
    }

    // --- Duplicate submissions --------------------------------------------------------

    public function test_a_task_already_charged_cannot_be_charged_again(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 2000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 2000)
            ->set('complete_deduction_reason', 'First charge.')
            ->call('complete');

        $this->assertEquals(1, DepositDeduction::where('tenancy_id', $tenancy->id)->count());

        // Attempting to charge the same, already-deducted task again via the merged action is refused.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all'])
            ->call('openLiabilityDecision', $task->fresh()->id);

        $this->assertEquals(1, DepositDeduction::where('tenancy_id', $tenancy->id)->count());
    }

    // --- Expense form: tenant charging ------------------------------------------------

    public function test_expense_form_charged_to_tenant_creates_an_expense_and_a_proposed_deduction_atomically(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $property = $house->block->property;

        Livewire::actingAs($landlord)
            ->test(ExpensesForm::class)
            ->set('property_id', $property->id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('expense_category_id', \App\Models\ExpenseCategory::firstOrCreate(['name' => 'Maintenance'])->id)
            ->set('amount', 2500)
            ->set('expense_date', now()->format('Y-m-d'))
            ->set('cost_responsibility', 'tenant')
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('deduction_amount', 2500)
            ->set('deduction_reason', 'Damaged fixture.')
            ->call('save');

        $expense = Expense::where('amount', 2500)->first();
        $this->assertNotNull($expense);
        $this->assertEquals($tenancy->id, $expense->tenancy_id);
        $this->assertNotNull($expense->deposit_deduction_id);

        $deduction = DepositDeduction::find($expense->deposit_deduction_id);
        $this->assertTrue($deduction->isAwaitingTenantResponse());
        $this->assertDatabaseHas('tenant_liability_decisions', [
            'decidable_type' => Expense::class, 'decidable_id' => $expense->id, 'decision_type' => 'tenant_liable',
        ]);
    }

    /** "A normal landlord expense must remain unchanged by default." */
    public function test_expense_form_without_tenant_charging_behaves_exactly_as_before(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(ExpensesForm::class)
            ->set('property_id', $property->id)
            ->set('expense_category_id', \App\Models\ExpenseCategory::firstOrCreate(['name' => 'Maintenance'])->id)
            ->set('amount', 1200)
            ->set('expense_date', now()->format('Y-m-d'))
            ->call('save');

        $expense = Expense::where('amount', 1200)->first();
        $this->assertNotNull($expense);
        $this->assertNull($expense->tenancy_id);
        $this->assertNull($expense->deposit_deduction_id);
        $this->assertEquals(0, DepositDeduction::count());
    }

    /**
     * Server-side tenancy/property association: expense recording is
     * landlord-only (ExpensePolicy::create() — no manager can reach this
     * form at all, confirmed separately), so the risk here is a landlord
     * selecting their own house but a tenancy id that belongs to someone
     * else's property entirely (e.g. a crafted request bypassing the
     * dropdown). The chosen house/tenancy must actually be associated —
     * exists:tenancies,id alone only proves the row exists somewhere.
     */
    public function test_expense_form_tenant_charging_validates_tenancy_belongs_to_the_chosen_house(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $property = $house->block->property;

        // An entirely unrelated tenancy, at a different property/landlord.
        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $otherLandlord->id, 'name' => 'Unrelated Property', 'type' => 'apartment', 'status' => 'active',
        ]);
        $otherBlock = Block::create(['property_id' => $otherProperty->id, 'name' => 'Main']);
        $otherHouse = House::create([
            'block_id' => $otherBlock->id, 'house_number' => 'U1', 'monthly_rent' => 10000,
            'deposit_amount' => 10000, 'status' => 'occupied',
        ]);
        $otherTenant = Tenant::create(['name' => 'Unrelated Tenant', 'phone' => '0700009876']);
        $otherTenancy = Tenancy::create([
            'tenant_id' => $otherTenant->id, 'house_id' => $otherHouse->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => 10000, 'deposit_required' => 10000, 'deposit_paid' => 10000,
            'status' => 'active',
        ]);

        Livewire::actingAs($landlord)
            ->test(ExpensesForm::class)
            ->set('property_id', $property->id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('expense_category_id', \App\Models\ExpenseCategory::firstOrCreate(['name' => 'Maintenance'])->id)
            ->set('amount', 1000)
            ->set('expense_date', now()->format('Y-m-d'))
            ->set('cost_responsibility', 'tenant')
            ->set('liability_tenancy_id', $otherTenancy->id) // crafted: not a tenancy of $house at all
            ->set('deduction_amount', 1000)
            ->set('deduction_reason', 'Attempting to charge an unrelated tenancy.')
            ->call('save')
            ->assertHasNoErrors(); // passes Livewire validation (the row exists) — refused inside the transaction instead

        $expense = Expense::where('amount', 1000)->where('house_id', $house->id)->first();
        $this->assertNotNull($expense, 'The plain expense is still recorded.');
        $this->assertNull($expense->deposit_deduction_id, 'But no deduction was ever created against the unrelated tenancy.');
        $this->assertEquals(0, DepositDeduction::where('tenancy_id', $otherTenancy->id)->count());
        $this->assertEquals(10000.0, $otherTenancy->fresh()->depositHeldBalance(), 'The unrelated tenancy\'s deposit is completely untouched.');
    }

    /** Expense recording — and therefore tenant-charging through this form — is landlord-only; no manager, however permissioned, can reach it at all. */
    public function test_expense_form_cannot_be_reached_by_any_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');
        \App\Models\Manager::create([
            'landlord_id' => $landlord->id, 'user_id' => $manager->id, 'name' => $manager->name,
            'phone' => '0700001234', 'email' => $manager->email, 'all_properties' => true, 'status' => 'active',
            'token' => \App\Models\Manager::generateToken(), 'expires_at' => now()->addDays(7), 'activated_at' => now(),
        ]);
        $manager->syncPermissions(['maintenance.manage_liability', 'maintenance.manage_costs']);

        Livewire::actingAs($manager)
            ->test(ExpensesForm::class)
            ->assertForbidden();
    }

    // --- Tenant acknowledge / dispute --------------------------------------------------

    public function test_tenant_acknowledging_a_proposed_deduction_makes_it_count_against_held_balance(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'ack-deduction-tenant@example.test', 'password'
        );

        $deduction = app(DepositService::class)->recordDeduction(
            $tenancy, 4000, 'Broken door', now()->toDateString(), $landlord->id, null, awaitingTenantAcknowledgement: true,
        );

        $this->assertEquals(15000.0, $tenancy->fresh()->depositHeldBalance());

        Livewire::actingAs($tenantUser)
            ->test(TenantDeposit::class)
            ->call('acknowledgeDeduction', $deduction->id)
            ->assertHasNoErrors();

        $deduction->refresh();
        $this->assertTrue($deduction->isAcknowledged());
        $this->assertEquals(11000.0, $tenancy->fresh()->depositHeldBalance());
    }

    /** Matches the brief's own worked example: disputed stays excluded from the confirmed held balance until resolved. */
    public function test_tenant_disputing_a_proposed_deduction_keeps_it_excluded_until_resolved(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord, depositPaid: 30000);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'dispute-deduction-tenant@example.test', 'password'
        );

        $deduction = app(DepositService::class)->recordDeduction(
            $tenancy, 8000, 'Door repair', now()->toDateString(), $landlord->id, null, awaitingTenantAcknowledgement: true,
        );

        $this->assertEquals(30000.0, $tenancy->fresh()->depositHeldBalance());

        Livewire::actingAs($tenantUser)
            ->test(TenantDeposit::class)
            ->call('startDispute', $deduction->id)
            ->set('dispute_reason', 'I did not cause this.')
            ->call('confirmDispute')
            ->assertHasNoErrors();

        // Still excluded — disputing, not acknowledging.
        $this->assertEquals(30000.0, $tenancy->fresh()->depositHeldBalance());

        // Landlord upholds the dispute — now it counts, even without a separate acknowledgement.
        app(DepositService::class)->resolveDispute($deduction->fresh(), 'uphold', $landlord->id);
        $this->assertEquals(22000.0, $tenancy->fresh()->depositHeldBalance());
    }

    // --- Void restoration --------------------------------------------------------------

    public function test_voiding_a_deduction_restores_the_held_balance_and_clears_task_and_expense_references(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'Tenant broke the door.')
            ->call('complete');

        $task->refresh();
        $deduction = DepositDeduction::find($task->deposit_deduction_id);
        $expense = $task->expense;

        // Acknowledge first, so it actually counts, to prove voiding reverses that too.
        app(DepositService::class)->acknowledgeDeduction($deduction, $tenancy->tenant_id);
        $this->assertEquals(11000.0, $tenancy->fresh()->depositHeldBalance());

        app(DepositService::class)->voidDeduction($deduction, 'Recorded in error.', $landlord->id);

        $this->assertEquals(15000.0, $tenancy->fresh()->depositHeldBalance(), 'Voiding restores the held balance.');
        $this->assertNull($task->fresh()->deposit_deduction_id, 'The task reference is cleared.');
        $this->assertNull($expense->fresh()->deposit_deduction_id, 'The expense reference is cleared.');

        // The liability decision is reversed too — a fresh decision is required before a new deduction.
        $this->assertNull(app(\App\Services\TenantLiabilityDecisionService::class)->activeDecisionFor($expense->fresh()));
        $this->assertFalse(app(\App\Services\MaintenanceCostService::class)->canDeductFromDeposit($task->fresh()));
    }

    public function test_a_fresh_decision_is_required_and_accepted_after_voiding(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->inProgressTask($house, $tenancy);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_actual_cost', 4000)
            ->set('complete_cost_responsibility', 'tenant')
            ->set('complete_liability_tenancy_id', $tenancy->id)
            ->set('complete_deduction_amount', 4000)
            ->set('complete_deduction_reason', 'First attempt.')
            ->call('complete');

        $task->refresh();
        $deduction = DepositDeduction::find($task->deposit_deduction_id);
        app(DepositService::class)->voidDeduction($deduction, 'Wrong tenant named.', $landlord->id);

        // A fresh decision (not the voided one) now succeeds via the Work Orders list.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all'])
            ->call('openLiabilityDecision', $task->fresh()->id)
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('liability_reason', 'Re-decided after voiding.')
            ->call('confirmTenantLiability')
            ->assertHasNoErrors();

        $this->assertEquals(2, DepositDeduction::where('tenancy_id', $tenancy->id)->count(), 'The voided one plus the fresh one.');
        $this->assertNotNull($task->fresh()->deposit_deduction_id);
    }

    // --- Balance visibility on both sides ----------------------------------------------

    public function test_updated_balance_is_visible_identically_on_landlord_and_tenant_sides(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'balance-visibility-tenant@example.test', 'password'
        );

        $deduction = app(DepositService::class)->recordDeduction(
            $tenancy, 5000, 'Damage', now()->toDateString(), $landlord->id, null, awaitingTenantAcknowledgement: true,
        );
        app(DepositService::class)->acknowledgeDeduction($deduction, $tenancy->tenant_id);

        $expectedHeld = $tenancy->fresh()->depositHeldBalance();
        $this->assertEquals(10000.0, $expectedHeld);

        $tenantHeld = Livewire::actingAs($tenantUser)->test(TenantDeposit::class)->viewData('held');
        $this->assertEquals($expectedHeld, $tenantHeld);

        // Landlord's Deposits list uses the exact same DepositService::heldBalance() call.
        $landlordRow = app(DepositService::class)->heldBalance($tenancy->fresh());
        $this->assertEquals($expectedHeld, $landlordRow);
    }

    // --- Work Orders badge accuracy -----------------------------------------------------

    /**
     * Post-implementation defect fix: the Work Orders badge used to show
     * "Deducted from tenant deposit" for every task with a deposit_deduction_id,
     * regardless of the deduction's actual status — including one still
     * awaiting the tenant's response, which doesn't count against the held
     * balance at all yet. Each of these asserts the badge text actually
     * shown for a given DepositDeduction state, and that the other states'
     * labels are absent.
     */
    private function completedTaskWithDeduction(House $house, Tenancy $tenancy, User $landlord, array $deductionAttributes = []): MaintenanceTask
    {
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'tenancy_id' => $tenancy->id, 'title' => 'Badge Task '.str()->random(6), 'category' => 'repairs',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => 4000, 'completed_at' => now(),
        ]);

        $deduction = DepositDeduction::create(array_merge([
            'tenancy_id' => $tenancy->id, 'amount' => 4000, 'reason' => 'Badge test',
            'deduction_date' => now()->toDateString(), 'recorded_by' => $landlord->id,
            'acknowledged_at' => null,
        ], $deductionAttributes));

        $task->update(['deposit_deduction_id' => $deduction->id]);

        return $task->fresh();
    }

    public function test_work_orders_badge_shows_awaiting_tenant_response(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithDeduction($house, $tenancy, $landlord);

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);

        $component->assertSee('Awaiting tenant response');
        $component->assertDontSee('Deducted from tenant deposit');
        $component->assertDontSee('Deduction disputed');
        $component->assertDontSee('Deduction voided');
    }

    public function test_work_orders_badge_shows_acknowledged_as_deducted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithDeduction($house, $tenancy, $landlord, [
            'acknowledged_at' => now(), 'acknowledged_by' => $tenancy->tenant_id,
        ]);

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);

        $component->assertSee('Deducted from tenant deposit');
        $component->assertDontSee('Awaiting tenant response');
        $component->assertDontSee('Deduction disputed');
        $component->assertDontSee('Deduction voided');
    }

    public function test_work_orders_badge_shows_dispute_pending(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithDeduction($house, $tenancy, $landlord, [
            'disputed_at' => now(), 'dispute_reason' => 'Not my fault.', 'disputed_by' => $tenancy->tenant_id,
            'dispute_resolved_at' => null,
        ]);

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);

        $component->assertSee('Deduction disputed');
        $component->assertDontSee('Awaiting tenant response');
        $component->assertDontSee('Deduction voided');
    }

    public function test_work_orders_badge_shows_voided(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithDeduction($house, $tenancy, $landlord, [
            'voided_at' => now(), 'void_reason' => 'Recorded in error.', 'voided_by' => $landlord->id,
        ]);

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);

        $component->assertSee('Deduction voided');
        $component->assertDontSee('Awaiting tenant response');
        $component->assertDontSee('Deduction disputed');
    }

    public function test_work_orders_badge_shows_upheld_after_dispute_as_deducted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithDeduction($house, $tenancy, $landlord, [
            'disputed_at' => now()->subDay(), 'dispute_reason' => 'Not my fault.', 'disputed_by' => $tenancy->tenant_id,
            'dispute_resolved_at' => now(),
        ]);

        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);

        $component->assertSee('upheld after dispute');
        $component->assertDontSee('Awaiting tenant response');
        $component->assertDontSee('Deduction voided');
    }
}
