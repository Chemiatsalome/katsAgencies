<?php

namespace Tests\Feature;

use App\Livewire\Expenses\Index as ExpensesIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Models\Block;
use App\Models\Expense;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\TenantLiabilityDecision;
use App\Models\User;
use App\Services\MaintenanceCostService;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Maintenance/Deposit Integration, Phase A: Maintenance\Index and
 * Expenses\Index used to record a tenant_liable/landlord_responsible
 * decision against two different decidables for the same real-world cost
 * (MaintenanceTask vs its own linked Expense) — deciding via one screen was
 * invisible to the other, and a landlord could end up declaring the same
 * expense liable twice. Expense is now the sole canonical decidable;
 * MaintenanceTask-side code resolves the decision via $task->expense.
 */
class MaintenanceExpenseLiabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function houseWithTenancy(User $landlord): array
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'Integration Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'INT-'.str()->random(6),
            'monthly_rent' => 15000, 'deposit_amount' => 15000, 'status' => 'occupied',
        ]);
        $tenant = Tenant::create(['name' => 'Integration Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]);
        $tenancy = Tenancy::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonths(6)->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'active',
        ]);

        return [$house, $tenancy];
    }

    private function completedTaskWithExpense(House $house, Tenancy $tenancy, float $cost = 4000): MaintenanceTask
    {
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'tenancy_id' => $tenancy->id, 'title' => 'Broken window', 'category' => 'repairs',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => $cost, 'completed_at' => now(),
        ]);

        app(MaintenanceCostService::class)->recordExpenseFor($task);

        return $task->refresh();
    }

    public function test_a_liability_decision_made_in_maintenance_is_recognized_in_expenses(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithExpense($house, $tenancy);

        // Direct maintenance/expense deposit charging: confirmTenantLiability()
        // now decides AND deducts together — liability_deduction_amount
        // is required (openLiabilityDecision() prefilled it to the full cost).
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('liability_reason', 'Tenant broke the window.')
            ->call('confirmTenantLiability')
            ->assertHasNoErrors();

        $expense = $task->expense;

        // Recorded against the Expense, never the task.
        $this->assertDatabaseHas('tenant_liability_decisions', [
            'decidable_type' => Expense::class, 'decidable_id' => $expense->id, 'decision_type' => 'tenant_liable',
        ]);
        $this->assertDatabaseMissing('tenant_liability_decisions', [
            'decidable_type' => MaintenanceTask::class, 'decidable_id' => $task->id,
        ]);

        // The merged action also deducted — awaiting the tenant's response,
        // linked back from both the Expense and the MaintenanceTask.
        $deduction = \App\Models\DepositDeduction::where('tenancy_id', $tenancy->id)->first();
        $this->assertNotNull($deduction);
        $this->assertTrue($deduction->isAwaitingTenantResponse());
        $this->assertEquals($deduction->id, $expense->fresh()->deposit_deduction_id);
        $this->assertEquals($deduction->id, $task->fresh()->deposit_deduction_id);

        // Visible on the Expenses page's own Liability column too, with no second decision.
        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->assertSee($tenancy->tenant->name.' liable');
    }

    public function test_a_liability_decision_made_in_expenses_is_recognized_in_maintenance(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithExpense($house, $tenancy);
        $expense = $task->expense;

        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->call('openLiabilityDecision', $expense->id)
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('liability_reason', 'Tenant broke the window.')
            ->call('confirmTenantLiability')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenant_liability_decisions', [
            'decidable_type' => Expense::class, 'decidable_id' => $expense->id, 'decision_type' => 'tenant_liable',
        ]);

        // MaintenanceCostService (the Maintenance tab's own eligibility check) now agrees.
        $this->assertTrue(app(MaintenanceCostService::class)->canDeductFromDeposit($task->fresh()));

        // The Work Orders list's own per-task decision lookup reflects it too.
        // status: 'all' — the tab's default 'open' filter excludes
        // 'completed' tasks entirely, which would hide this one.
        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'scheduled', 'status' => 'all']);
        $decisionsByTaskId = $component->viewData('decisionsByTaskId');
        $this->assertNotNull($decisionsByTaskId[$task->id] ?? null);
        $this->assertTrue($decisionsByTaskId[$task->id]->isTenantLiable());
    }

    /**
     * Direct maintenance/expense deposit charging: confirmTenantLiability()
     * now decides AND deducts together, so charging via one screen leaves
     * an unvoided deduction behind — decide()'s own existing guard then
     * refuses a second decision from the other screen outright (rather
     * than silently amending it), exactly satisfying "duplicate liability
     * decisions are not created." Voiding the deduction first (tested
     * separately) is the only way to make a fresh decision.
     */
    public function test_deciding_from_both_screens_never_creates_two_active_decisions(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithExpense($house, $tenancy);
        $expense = $task->expense;

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->set('liability_tenancy_id', $tenancy->id)
            ->set('liability_reason', 'First decision, via Maintenance.')
            ->call('confirmTenantLiability')
            ->assertHasNoErrors();

        $this->assertEquals(1, TenantLiabilityDecision::where('decidable_type', Expense::class)
            ->where('decidable_id', $expense->id)->count());

        // A landlord who doesn't realise it's already charged tries again from Expenses.
        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->call('openLiabilityDecision', $expense->id)
            ->set('liability_reason', 'Re-confirmed, via Expenses.')
            ->call('markLandlordResponsible')
            ->assertHasErrors('liability_reason');

        // Still exactly one decision — the original, untouched, still tenant_liable.
        $this->assertEquals(1, TenantLiabilityDecision::where('decidable_type', Expense::class)
            ->where('decidable_id', $expense->id)->count());

        $active = app(TenantLiabilityDecisionService::class)->activeDecisionFor($expense->fresh());
        $this->assertNotNull($active);
        $this->assertTrue($active->isTenantLiable());
    }

    public function test_a_task_with_no_linked_expense_is_handled_safely_not_crashed(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);

        // A completed task with a cost of exactly 0 — recordExpenseFor()'s
        // own guard means no Expense is ever created for it.
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'tenancy_id' => $tenancy->id, 'title' => 'No-cost visit', 'category' => 'repairs',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => 0, 'completed_at' => now(),
        ]);
        $this->assertNull($task->expense_id);

        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->assertHasNoErrors();

        // No modal state was set, no decision/exception — a clean, flashed refusal.
        $this->assertDatabaseMissing('tenant_liability_decisions', ['decidable_type' => MaintenanceTask::class, 'decidable_id' => $task->id]);
        $this->assertFalse(app(MaintenanceCostService::class)->canDeductFromDeposit($task));
    }

    /** Existing safeguards (tenant confirmation, manager permission) are untouched by the canonical-key change. */
    public function test_tenant_confirmation_and_authorization_rules_remain_enforced(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);

        $complaint = \App\Models\Complaint::create([
            'house_id' => $house->id, 'tenant_id' => $tenancy->tenant_id,
            'category' => 'plumbing', 'subject' => 'Leak', 'description' => 'Leaking pipe',
            'priority' => 'medium', 'status' => 'resolved', 'tenant_confirmed_at' => null,
        ]);
        $task = MaintenanceTask::create([
            'property_id' => $house->block->property_id, 'block_id' => $house->block_id, 'house_id' => $house->id,
            'complaint_id' => $complaint->id, 'title' => 'Fix leak', 'category' => 'plumbing',
            'priority' => 'medium', 'status' => 'completed', 'actual_cost' => 2500, 'completed_at' => now(),
        ]);
        app(MaintenanceCostService::class)->recordExpenseFor($task);
        $task->refresh();

        app(TenantLiabilityDecisionService::class)->decide($task->expense, 'tenant_liable', $tenancy->id, 'Confirmed.', $landlord->id);

        // Tenant hasn't confirmed the fix yet — still refused, decision notwithstanding.
        $this->assertFalse(app(MaintenanceCostService::class)->canDeductFromDeposit($task->fresh()));

        // A manager without maintenance.manage_liability still cannot decide liability at all.
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');
        \App\Models\Manager::create([
            'landlord_id' => $landlord->id, 'user_id' => $manager->id, 'name' => $manager->name,
            'phone' => '0700009999', 'email' => $manager->email, 'all_properties' => true, 'status' => 'active',
            'token' => \App\Models\Manager::generateToken(), 'expires_at' => now()->addDays(7), 'activated_at' => now(),
        ]);
        $manager->syncPermissions(['maintenance.view']);

        Livewire::actingAs($manager)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openLiabilityDecision', $task->id)
            ->assertForbidden();
    }

    /**
     * A decision row created against a MaintenanceTask before this phase
     * (simulating a legacy record — none exist in the real database, but
     * nothing in this change deletes or migrates any pre-existing row) must
     * remain in the database, queryable by direct lookup, and must not be
     * silently destroyed — even though current eligibility logic no longer
     * treats it as the active decision for deduction purposes.
     */
    public function test_a_legacy_task_keyed_decision_remains_in_the_database_and_is_not_deleted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$house, $tenancy] = $this->houseWithTenancy($landlord);
        $task = $this->completedTaskWithExpense($house, $tenancy);

        $legacyDecision = TenantLiabilityDecision::create([
            'decidable_type' => MaintenanceTask::class, 'decidable_id' => $task->id,
            'decision_type' => 'tenant_liable', 'tenancy_id' => $tenancy->id,
            'decided_by' => $landlord->id, 'decided_at' => now()->subMonths(3), 'reason' => 'Legacy pre-Phase-A record.',
        ]);

        // Still there, still directly queryable — nothing silently orphaned or deleted.
        $this->assertDatabaseHas('tenant_liability_decisions', ['id' => $legacyDecision->id]);
        $this->assertNotNull(TenantLiabilityDecision::find($legacyDecision->id));

        // But current eligibility logic no longer looks at it — only the task's own Expense counts.
        $this->assertFalse(app(MaintenanceCostService::class)->canDeductFromDeposit($task));

        // Deciding properly now (against the Expense) still works normally alongside the untouched legacy row.
        app(TenantLiabilityDecisionService::class)->decide($task->expense, 'tenant_liable', $tenancy->id, 'Correctly recorded now.', $landlord->id);
        $this->assertTrue(app(MaintenanceCostService::class)->canDeductFromDeposit($task->fresh()));
        $this->assertDatabaseHas('tenant_liability_decisions', ['id' => $legacyDecision->id]);
    }
}
