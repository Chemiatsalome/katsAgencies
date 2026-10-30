<?php

namespace Tests\Feature;

use App\Livewire\Expenses\Index as ExpensesIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Models\Block;
use App\Models\Complaint;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MaintenanceCostService;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tier 2 (design approved 2026-10-25), §7: the direct-expense deduction
 * path (Expenses\Index) used to resolve "who's liable" as House::
 * currentTenancy() — whoever occupies the unit *right now* — with zero
 * regard for the expense's own date, and could actually execute a
 * deduction against that wrong, current tenant's deposit. These tests
 * cover the fix: occupancy is a candidate signal only, a liability
 * decision is always an explicit human record, and a decided tenancy with
 * no deposit left is refused outright rather than substituted.
 */
class TenantLiabilityDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function houseWithTwoTenancies(User $landlord): array
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Liability Test Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'LT-'.str()->random(6),
            'monthly_rent' => 15000, 'deposit_amount' => 15000, 'status' => 'vacant',
        ]);

        $formerTenant = Tenant::create(['name' => 'Former Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $formerTenancy = Tenancy::create([
            'tenant_id' => $formerTenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subYear()->format('Y-m-d'),
            'move_out_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'completed',
        ]);

        $currentTenant = Tenant::create(['name' => 'Current Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $currentTenancy = Tenancy::create([
            'tenant_id' => $currentTenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'active',
        ]);

        $house->update(['status' => 'occupied']);

        return [$house, $formerTenancy, $currentTenancy];
    }

    public function test_occupying_on_resolves_the_tenancy_that_was_actually_in_place_on_the_expense_date(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        $matchesDuringFormer = Tenancy::occupyingOn($house->id, now()->subMonths(6))->get();
        $this->assertCount(1, $matchesDuringFormer);
        $this->assertEquals($formerTenancy->id, $matchesDuringFormer->first()->id);

        $matchesNow = Tenancy::occupyingOn($house->id, now())->get();
        $this->assertCount(1, $matchesNow);
        $this->assertEquals($currentTenancy->id, $matchesNow->first()->id);
    }

    public function test_expense_dated_during_the_former_tenants_occupancy_candidates_the_former_tenant_not_the_current_one(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Pre-existing damage repair',
        ]);

        $component = Livewire::actingAs($landlord)->test(ExpensesIndex::class);
        $candidate = $component->instance()->candidateTenancyFor($expense);

        $this->assertEquals('single', $candidate['kind']);
        $this->assertEquals($formerTenancy->id, $candidate['tenancy']->id);
        $this->assertNotEquals($currentTenancy->id, $candidate['tenancy']->id);
    }

    public function test_deduction_is_not_eligible_until_an_explicit_liability_decision_exists(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);

        $component = Livewire::actingAs($landlord)->test(ExpensesIndex::class);
        $this->assertFalse($component->instance()->canDeductFromDeposit($expense), 'No decision recorded yet — never eligible.');

        app(TenantLiabilityDecisionService::class)->decide($expense, 'tenant_liable', $formerTenancy->id, 'Tenant confirmed causing it.', $landlord->id);

        $component = Livewire::actingAs($landlord)->test(ExpensesIndex::class);
        $this->assertTrue($component->instance()->canDeductFromDeposit($expense->fresh()));
    }

    public function test_confirming_a_deduction_uses_the_decided_tenancy_never_the_current_occupant(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);

        app(TenantLiabilityDecisionService::class)->decide($expense, 'tenant_liable', $formerTenancy->id, 'Confirmed.', $landlord->id);

        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->call('startDeduction', $expense->id)
            ->set('deduction_amount', '5000')
            ->set('deduction_date', now()->format('Y-m-d'))
            ->call('confirmDeduction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_deductions', ['tenancy_id' => $formerTenancy->id, 'amount' => 5000]);
        $this->assertDatabaseMissing('deposit_deductions', ['tenancy_id' => $currentTenancy->id]);
        $this->assertEquals(15000.0, $currentTenancy->fresh()->depositHeldBalance(), 'The current tenant\'s deposit must be completely untouched — unchanged from its original 15,000.');
        $this->assertEquals(10000.0, $formerTenancy->fresh()->depositHeldBalance(), 'The former tenant\'s own deposit is the one that absorbed the 5,000 deduction.');
    }

    public function test_deduction_is_refused_when_the_decided_tenancys_deposit_is_unavailable_never_falls_back(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        // The former tenant's deposit has already been fully refunded.
        \App\Models\DepositRefund::create([
            'tenancy_id' => $formerTenancy->id, 'amount' => 15000, 'refund_date' => now()->subMonth()->format('Y-m-d'),
        ]);
        $this->assertEquals(0.0, $formerTenancy->fresh()->depositHeldBalance());

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);

        app(TenantLiabilityDecisionService::class)->decide($expense, 'tenant_liable', $formerTenancy->id, 'Confirmed.', $landlord->id);

        $component = Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->call('startDeduction', $expense->id);

        // canDeductFromDeposit() already excludes it (no deposit left), so
        // the "Deduct" action never opens — startDeduction() no-ops.
        $this->assertNull($component->get('deductingExpenseId'));
        $this->assertDatabaseMissing('deposit_deductions', ['tenancy_id' => $currentTenancy->id]);
        $this->assertEquals(15000.0, $currentTenancy->fresh()->depositHeldBalance(), 'Never substituted — the current tenant\'s deposit is untouched.');
    }

    public function test_landlord_responsible_decision_blocks_deduction_even_with_a_valid_candidate(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Structural plumbing failure',
        ]);

        app(TenantLiabilityDecisionService::class)->decide($expense, 'landlord_responsible', null, 'Building-wide plumbing fault, not tenant-caused.', $landlord->id);

        $component = Livewire::actingAs($landlord)->test(ExpensesIndex::class);
        $this->assertFalse($component->instance()->canDeductFromDeposit($expense->fresh()));
    }

    public function test_deciding_again_amends_the_prior_decision_leaving_only_one_active(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);
        $service = app(TenantLiabilityDecisionService::class);

        $first = $service->decide($expense, 'tenant_liable', $formerTenancy->id, 'Initial.', $landlord->id);
        $second = $service->decide($expense, 'landlord_responsible', null, 'Re-reviewed — actually ours.', $landlord->id);

        $this->assertTrue($first->refresh()->isActive() === false);
        $this->assertEquals($second->id, $first->superseded_by_id);
        $this->assertTrue($second->isActive());
        $this->assertEquals(1, \App\Models\TenantLiabilityDecision::where('decidable_type', $expense->getMorphClass())
            ->where('decidable_id', $expense->id)->active()->count());
    }

    public function test_amending_a_decision_is_blocked_once_a_deduction_already_exists(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy] = $this->houseWithTwoTenancies($landlord);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);
        $service = app(TenantLiabilityDecisionService::class);
        $service->decide($expense, 'tenant_liable', $formerTenancy->id, 'Confirmed.', $landlord->id);

        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->call('startDeduction', $expense->id)
            ->set('deduction_amount', '5000')
            ->set('deduction_date', now()->format('Y-m-d'))
            ->call('confirmDeduction');

        $this->expectException(\RuntimeException::class);
        $service->decide($expense->fresh(), 'landlord_responsible', null, 'Changed my mind.', $landlord->id);
    }

    public function test_manager_cannot_create_a_liability_decision(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house] = $this->houseWithTwoTenancies($landlord);

        $managerUser = User::factory()->create(['status' => 'active']);
        $managerUser->assignRole('manager');
        \App\Models\Manager::create([
            'landlord_id' => $landlord->id, 'user_id' => $managerUser->id, 'name' => $managerUser->name,
            'phone' => '0700333444', 'email' => $managerUser->email, 'all_properties' => true, 'status' => 'active',
            'token' => \App\Models\Manager::generateToken(), 'expires_at' => now()->addDays(7), 'activated_at' => now(),
        ]);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Maintenance']);
        $expense = Expense::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'expense_category_id' => $category->id, 'amount' => 5000,
            'expense_date' => now()->subMonths(6)->format('Y-m-d'), 'description' => 'Damage',
        ]);

        $this->assertFalse($managerUser->can('create', \App\Models\TenantLiabilityDecision::class));
    }

    public function test_maintenance_task_liability_follows_the_same_rule_as_direct_expenses(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $formerTenancy, $currentTenancy] = $this->houseWithTwoTenancies($landlord);

        $complaint = Complaint::create([
            'house_id' => $house->id, 'tenant_id' => $formerTenancy->tenant_id,
            'category' => 'plumbing', 'subject' => 'Leak', 'description' => 'Leaking pipe',
            'priority' => 'medium', 'status' => 'resolved', 'tenant_confirmed_at' => now()->subMonths(5),
        ]);
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'complaint_id' => $complaint->id, 'title' => 'Fix leak', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => 3000, 'completed_at' => now()->subMonths(5),
        ]);

        $costService = app(MaintenanceCostService::class);
        $candidate = $costService->candidateTenancyFor($task);
        // The complaint's own tenant is the former tenant, even though they've since moved out.
        $this->assertEquals('single', $candidate['kind']);
        $this->assertEquals($formerTenancy->id, $candidate['tenancy']->id);

        $this->assertFalse($costService->canDeductFromDeposit($task), 'No liability decision recorded yet.');

        app(TenantLiabilityDecisionService::class)->decide($task, 'tenant_liable', $formerTenancy->id, 'Confirmed.', $landlord->id);

        $this->assertTrue($costService->canDeductFromDeposit($task->fresh()));

        $deduction = $costService->deductFromTenantDeposit($task->fresh(), $landlord->id);
        $this->assertEquals($formerTenancy->id, $deduction->tenancy_id);
        $this->assertNotEquals($currentTenancy->id, $deduction->tenancy_id);
    }
}
