<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Maintenance\Schedules\Form as ScheduleForm;
use App\Livewire\Maintenance\Schedules\Index as SchedulesIndex;
use App\Models\Complaint;
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
}
