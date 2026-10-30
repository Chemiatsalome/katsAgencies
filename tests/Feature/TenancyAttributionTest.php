<?php

namespace Tests\Feature;

use App\Livewire\Expenses\Index as ExpensesIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Tenancies\Show as TenanciesShow;
use App\Livewire\Tenant\Maintenance\Create as TenantMaintenanceCreate;
use App\Models\Block;
use App\Models\Complaint;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MaintenanceCostService;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * §8 (design approved 2026-10-25): property-level history vs. tenancy
 * attribution vs. tenant liability, kept as three separate concepts.
 * tenancy_id on Complaint/MaintenanceTask/Expense is captured only at the
 * one reliable point (Tenant\Maintenance\Create::submit()) and inherited
 * forward — never reconstructed from house_id/tenant_id/created_at.
 */
class TenancyAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function houseWithTwoTenancies(User $landlord): array
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Attrib Test Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'AT-'.str()->random(6),
            'monthly_rent' => 15000, 'deposit_amount' => 15000, 'status' => 'vacant',
        ]);

        $tenantA = Tenant::create(['name' => 'Tenant A '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $tenancyA = Tenancy::create([
            'tenant_id' => $tenantA->id, 'house_id' => $house->id,
            'move_in_date' => now()->subYear()->format('Y-m-d'),
            'move_out_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'completed',
        ]);

        $tenantB = Tenant::create(['name' => 'Tenant B '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $tenancyB = Tenancy::create([
            'tenant_id' => $tenantB->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'active',
        ]);

        $house->update(['status' => 'occupied']);

        return [$house, $tenancyA, $tenancyB];
    }

    public function test_tenant_b_does_not_inherit_tenant_as_open_maintenance_in_the_termination_summary(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancyA, $tenancyB] = $this->houseWithTwoTenancies($landlord);

        // Tenant A's own leftover open task, tied to A's tenancy, never resolved.
        $complaintA = Complaint::create([
            'tenant_id' => $tenancyA->tenant_id, 'house_id' => $house->id, 'tenancy_id' => $tenancyA->id,
            'category' => 'plumbing', 'subject' => 'Leak', 'description' => 'Leak', 'priority' => 'medium', 'status' => 'submitted',
        ]);
        MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'house_id' => $house->id, 'tenancy_id' => $tenancyA->id,
            'complaint_id' => $complaintA->id, 'title' => 'Fix leak', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'submitted',
        ]);

        $component = Livewire::actingAs($landlord)
            ->test(TenanciesShow::class, ['tenancy' => $tenancyB])
            ->set('moveOutStatus', 'completed')
            ->set('actualMoveOutDate', now()->format('Y-m-d'))
            ->set('acknowledgeUnpaidBalance', true)
            ->call('reviewTermination')
            ->assertHasNoErrors();

        $built = $component->viewData('terminationSummary');
        $this->assertNotNull($built, 'reviewTermination() must have set showTerminationSummary.');

        $this->assertEquals(0, $built['openMaintenanceOnThisTenancyCount'], 'Tenant A\'s open task must never count against Tenant B\'s tenancy.');
        $this->assertEquals(1, $built['openMaintenanceOtherOnUnitCount'], 'It still shows up, honestly labeled as unit-level, not claimed as Tenant B\'s.');
    }

    public function test_property_level_expense_history_includes_both_tenancies_expenses(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancyA, $tenancyB] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expenseA = Expense::create([
            'property_id' => $house->block->property_id, 'house_id' => $house->id, 'tenancy_id' => $tenancyA->id,
            'expense_category_id' => $category->id, 'amount' => 1000, 'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'A\'s era',
        ]);
        $expenseB = Expense::create([
            'property_id' => $house->block->property_id, 'house_id' => $house->id, 'tenancy_id' => $tenancyB->id,
            'expense_category_id' => $category->id, 'amount' => 2000, 'expense_date' => now()->format('Y-m-d'), 'description' => 'B\'s era',
        ]);

        $html = Livewire::actingAs($landlord)->test(ExpensesIndex::class)->html();

        // The list table doesn't render `description` as visible text —
        // assert on the amounts (and the underlying data) instead.
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('2,000.00', $html);
        $this->assertDatabaseHas('expenses', ['id' => $expenseA->id, 'tenancy_id' => $tenancyA->id]);
        $this->assertDatabaseHas('expenses', ['id' => $expenseB->id, 'tenancy_id' => $tenancyB->id]);
    }

    public function test_tenancy_id_is_captured_at_tenant_complaint_creation_and_inherited_through_task_and_expense(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();
        $tenantUser = User::factory()->create();
        $tenantUser->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $tenantUser->id]);

        Livewire::actingAs($tenantUser)
            ->test(TenantMaintenanceCreate::class)
            ->set('category', 'plumbing')
            ->set('subject', 'Tap leaking')
            ->set('description', 'Kitchen tap leaking for two days.')
            ->set('priority', 'normal')
            ->call('submit')
            ->assertHasNoErrors();

        $complaint = Complaint::where('subject', 'Tap leaking')->firstOrFail();
        $this->assertEquals($tenancy->id, $complaint->tenancy_id, 'Captured directly at creation — not guessed.');

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class)
            ->call('convertToTask', $complaint->id);

        $task = MaintenanceTask::where('complaint_id', $complaint->id)->firstOrFail();
        $this->assertEquals($tenancy->id, $task->tenancy_id, 'Inherited from the complaint, not re-derived.');

        $task->update(['actual_cost' => 1500, 'completed_at' => now()]);
        $expense = app(MaintenanceCostService::class)->recordExpenseFor($task->fresh());

        $this->assertEquals($tenancy->id, $expense->tenancy_id, 'Inherited from the task, not re-derived.');
    }

    public function test_a_landlord_logged_task_with_no_complaint_has_no_tenancy_id_ever(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class)
            ->set('task_property_id', $property->id)
            ->set('task_title', 'Routine inspection')
            ->set('task_category', 'general')
            ->set('task_priority', 'low')
            ->call('createTask');

        $task = MaintenanceTask::where('title', 'Routine inspection')->firstOrFail();
        $this->assertNull($task->tenancy_id, 'No tenant involved at all — never guessed from house_id or created_at.');

        $task->update(['actual_cost' => 500, 'completed_at' => now()]);
        $expense = app(MaintenanceCostService::class)->recordExpenseFor($task->fresh());
        $this->assertNull($expense->tenancy_id);
    }

    public function test_repeat_tenancy_same_tenant_same_house_resolves_the_correct_stay_not_the_latest(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Repeat Block '.str()->random(6)]);
        $house = House::create(['block_id' => $block->id, 'house_number' => 'RP-'.str()->random(6), 'monthly_rent' => 10000, 'deposit_amount' => 10000, 'status' => 'vacant']);

        $tenant = Tenant::create(['name' => 'Returning Tenant', 'phone' => '07'.random_int(10000000, 99999999)]);
        $firstStay = Tenancy::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subYear()->format('Y-m-d'), 'move_out_date' => now()->subMonths(9)->format('Y-m-d'),
            'monthly_rent' => 10000, 'deposit_required' => 10000, 'status' => 'completed',
        ]);
        $secondStay = Tenancy::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => 10000, 'deposit_required' => 10000, 'status' => 'active',
        ]);

        // A complaint filed during the FIRST stay, with no tenancy_id captured (simulating a pre-§8 record).
        // Eloquent always overwrites created_at on save() to "now" regardless
        // of what's passed to create() — a raw query-builder update bypasses
        // that to actually backdate it for this test.
        $complaint = Complaint::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'category' => 'plumbing', 'subject' => 'Old issue', 'description' => 'From the first stay',
            'priority' => 'medium', 'status' => 'resolved',
        ]);
        Complaint::whereKey($complaint->id)->update(['created_at' => now()->subMonths(10)]);
        $task = MaintenanceTask::create([
            'property_id' => $property->id, 'house_id' => $house->id, 'complaint_id' => $complaint->id,
            'title' => 'Old fix', 'category' => 'plumbing', 'priority' => 'medium', 'status' => 'completed',
        ]);

        $candidate = app(MaintenanceCostService::class)->candidateTenancyFor($task->fresh());

        $this->assertEquals('single', $candidate['kind']);
        $this->assertEquals($firstStay->id, $candidate['tenancy']->id, 'Must resolve to the stay the complaint actually occurred during, not whichever is most recent.');
        $this->assertNotEquals($secondStay->id, $candidate['tenancy']->id);
    }

    public function test_association_and_liability_are_separate_a_captured_tenancy_id_does_not_authorize_deduction(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancyA] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'house_id' => $house->id, 'tenancy_id' => $tenancyA->id,
            'expense_category_id' => $category->id, 'amount' => 3000, 'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Linked but undecided',
        ]);

        // Association is known (tenancy_id set) — but liability is a separate, explicit decision §7 still requires.
        $this->assertNotNull($expense->tenancy_id);

        $component = Livewire::actingAs($landlord)->test(ExpensesIndex::class);
        $this->assertFalse($component->instance()->canDeductFromDeposit($expense), 'A known association must never, by itself, authorize a deduction.');

        $candidate = $component->instance()->candidateTenancyFor($expense);
        $this->assertEquals('single', $candidate['kind']);
        $this->assertEquals($tenancyA->id, $candidate['tenancy']->id);

        // Only the explicit liability decision actually unlocks it.
        app(TenantLiabilityDecisionService::class)->decide($expense, 'tenant_liable', $tenancyA->id, 'Confirmed.', $landlord->id);
        $this->assertTrue(Livewire::actingAs($landlord)->test(ExpensesIndex::class)->instance()->canDeductFromDeposit($expense->fresh()));
    }

    public function test_tenancy_specific_deposit_and_dispute_records_are_unaffected_by_this_change(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancyA, $tenancyB] = $this->houseWithTwoTenancies($landlord);

        $deduction = \App\Models\DepositDeduction::create([
            'tenancy_id' => $tenancyA->id, 'amount' => 2000, 'reason' => 'Pre-existing', 'deduction_date' => now()->subMonths(5)->format('Y-m-d'),
        ]);

        $this->assertDatabaseHas('deposit_deductions', ['id' => $deduction->id, 'tenancy_id' => $tenancyA->id]);
        $this->assertEquals(0, $tenancyB->depositDeductions()->count(), 'Tenant B\'s own deduction set is untouched.');
    }
}
