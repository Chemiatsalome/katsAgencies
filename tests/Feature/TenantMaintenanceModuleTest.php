<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as LandlordMaintenanceIndex;
use App\Livewire\Tenant\Maintenance\Create;
use App\Livewire\Tenant\Maintenance\Index as TenantMaintenanceIndex;
use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\MaintenanceTask;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TenantMaintenanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_tenant_can_submit_a_maintenance_request_with_a_photo_and_landlord_is_notified(): void
    {
        Storage::fake('public');
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'plumbing')
            ->set('subject', 'Leaking tap')
            ->set('description', 'The kitchen tap has been leaking for two days.')
            ->set('priority', 'urgent')
            ->set('photo', UploadedFile::fake()->image('leak.jpg'))
            ->call('submit')
            ->assertHasNoErrors();

        $complaint = Complaint::where('subject', 'Leaking tap')->firstOrFail();
        $this->assertEquals('high', $complaint->priority); // urgent -> high mapping
        $this->assertEquals($user->tenant->id, $complaint->tenant_id);
        $this->assertEquals(1, $complaint->documents()->count());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'complaint_submitted')
                ->exists()
        );
    }

    public function test_tenant_cannot_submit_without_an_active_tenancy(): void
    {
        $this->seed();

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('subject', 'Test')
            ->set('description', 'Test description')
            ->call('submit');

        $this->assertDatabaseMissing('complaints', ['subject' => 'Test']);
    }

    public function test_tenant_sees_their_own_requests_and_status_updates_when_landlord_progresses_the_task(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        $complaint = Complaint::create([
            'tenant_id' => $user->tenant->id,
            'house_id' => $tenancy->house_id,
            'property_id' => $tenancy->house->block->property_id,
            'category' => 'plumbing',
            'subject' => 'Blocked drain',
            'description' => 'Drain is blocked in the bathroom.',
            'priority' => 'medium',
            'status' => 'submitted',
            'submitted_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(TenantMaintenanceIndex::class)
            ->assertSee('Blocked drain')
            ->assertSee('Submitted');

        Livewire::actingAs($landlord)
            ->test(LandlordMaintenanceIndex::class)
            ->call('convertToTask', $complaint->id);

        $task = MaintenanceTask::where('complaint_id', $complaint->id)->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(LandlordMaintenanceIndex::class)
            ->call('markReviewed', $task->id);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $user->tenant->getMorphClass())
                ->where('notifiable_id', $user->tenant->id)
                ->where('event_type', 'maintenance_status_reviewed')
                ->exists()
        );

        Livewire::actingAs($user)
            ->test(TenantMaintenanceIndex::class)
            ->assertSee('Under Review');
    }

    public function test_tenant_cannot_view_another_tenants_complaint(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $user->tenant->id)->with('house.block.property')->firstOrFail();
        $otherComplaint = Complaint::create([
            'tenant_id' => $otherTenancy->tenant_id,
            'house_id' => $otherTenancy->house_id,
            'property_id' => $otherTenancy->house->block->property_id,
            'category' => 'electrical',
            'subject' => 'Not my problem',
            'description' => 'Belongs to someone else.',
            'priority' => 'low',
            'status' => 'submitted',
        ]);

        Livewire::actingAs($user)
            ->test(TenantMaintenanceIndex::class)
            ->assertDontSee('Not my problem');

        $this->assertFalse($user->can('view', $otherComplaint));
    }
}
