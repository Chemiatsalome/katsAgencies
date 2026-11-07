<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\User;
use App\Services\MaintenanceCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7A final follow-up: MaintenanceCostService::recordExpenseFor()'s own
 * zero-cost guard used to be `! $task->actual_cost` — always false for the
 * decimal-cast string "0.00" (PHP's `!` only treats ""/"0" as falsy, not
 * "0.00"), so a genuinely zero-cost completion still created a zero-amount
 * Expense row. Fixed to an explicit `(float) $task->actual_cost <= 0`
 * comparison. Tests call the service directly, not through the Livewire
 * component, to isolate the guard itself from the authorization layer
 * covered separately in MaintenanceTaskPermissionSeparationTest.
 */
class MaintenanceCostServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTask(Property $property, ?float $actualCost): MaintenanceTask
    {
        return MaintenanceTask::create([
            'property_id' => $property->id,
            'title' => 'Test task',
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'in_progress',
            'actual_cost' => $actualCost,
        ]);
    }

    public function test_recording_an_expense_for_a_zero_actual_cost_task_creates_no_expense(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property, 0);

        $result = app(MaintenanceCostService::class)->recordExpenseFor($task);

        $this->assertNull($result);
        $this->assertNull($task->fresh()->expense_id);
        $this->assertEquals(0, Expense::where('property_id', $property->id)->count());
    }

    /** A null actual_cost (never set at all) must behave identically to an explicit zero. */
    public function test_recording_an_expense_for_a_null_actual_cost_task_creates_no_expense(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property, null);

        $result = app(MaintenanceCostService::class)->recordExpenseFor($task);

        $this->assertNull($result);
        $this->assertNull($task->fresh()->expense_id);
    }

    public function test_recording_an_expense_for_a_positive_actual_cost_task_creates_the_expected_expense(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property, 2500);

        $expense = app(MaintenanceCostService::class)->recordExpenseFor($task);

        $this->assertNotNull($expense);
        $this->assertEquals($task->fresh()->expense_id, $expense->id);
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'property_id' => $property->id,
            'amount' => 2500,
        ]);
    }

    /** Marking a task "completed" twice (e.g. a double-click) must never create a duplicate expense — unchanged by the guard fix. */
    public function test_recording_an_expense_twice_for_the_same_positive_cost_task_is_idempotent(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property, 1800);

        $service = app(MaintenanceCostService::class);
        $first = $service->recordExpenseFor($task);
        $second = $service->recordExpenseFor($task->fresh());

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Expense::where('property_id', $property->id)->where('amount', 1800)->count());
    }

    /** A task already linked to an expense must never gain a second one, even if its actual_cost later changes. */
    public function test_recording_an_expense_is_a_no_op_once_the_task_already_has_one(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $task = $this->makeTask($property, 1000);
        $first = app(MaintenanceCostService::class)->recordExpenseFor($task);

        $task->fresh()->update(['actual_cost' => 9999]);

        $result = app(MaintenanceCostService::class)->recordExpenseFor($task->fresh());

        $this->assertEquals($first->id, $result->id);
        $this->assertEquals(1, Expense::where('property_id', $property->id)->count());
    }
}
