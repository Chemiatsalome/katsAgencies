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
        Storage::fake('local');
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

    /**
     * Phase 7A, F2: Create::CATEGORIES already offered 'appliance' as a
     * selectable option, but complaints.category never included it in its
     * enum — submitting threw a data-truncation error under MySQL strict
     * mode before the enum was widened.
     */
    public function test_tenant_can_submit_an_appliance_complaint_and_the_category_persists(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'appliance')
            ->set('subject', 'Fridge not cooling')
            ->set('description', 'The fridge stopped cooling overnight.')
            ->set('priority', 'normal')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('complaints', [
            'subject' => 'Fridge not cooling',
            'category' => 'appliance',
        ]);
    }

    /** Phase 7B, F1: 'emergency' is now a reachable priority from the tenant's own form. */
    public function test_tenant_can_submit_an_emergency_priority_request_and_it_persists(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'electrical')
            ->set('subject', 'Sparking outlet')
            ->set('description', 'The kitchen outlet is sparking.')
            ->set('priority', 'emergency')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('complaints', [
            'subject' => 'Sparking outlet',
            'priority' => 'emergency',
        ]);
    }

    /**
     * Phase 7B, F11: an emergency complaint must notify the landlord via
     * both in_app and email — exactly 2 AppNotification rows, one per
     * channel, no more and no fewer (duplicate-delivery check), and the
     * email actually dispatches.
     */
    public function test_an_emergency_complaint_notifies_the_landlord_via_in_app_and_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'water')
            ->set('subject', 'Burst pipe flooding the kitchen')
            ->set('description', 'Water everywhere, needs immediate attention.')
            ->set('priority', 'emergency')
            ->call('submit')
            ->assertHasNoErrors();

        $notifications = AppNotification::where('notifiable_type', $landlord->getMorphClass())
            ->where('notifiable_id', $landlord->id)
            ->where('event_type', 'complaint_submitted')
            ->get();

        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $notifications->pluck('channel')->all());
        $this->assertTrue($notifications->every(fn ($n) => $n->status === 'sent'));

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\GenericNotificationMail::class, 1);
    }

    /** Regression: a non-emergency complaint must keep its original, unchanged in-app-only behavior. */
    public function test_a_non_emergency_complaint_still_only_notifies_the_landlord_via_in_app(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'plumbing')
            ->set('subject', 'Slow drain')
            ->set('description', 'The bathroom sink drains slowly.')
            ->set('priority', 'urgent')
            ->call('submit')
            ->assertHasNoErrors();

        $notifications = AppNotification::where('notifiable_type', $landlord->getMorphClass())
            ->where('notifiable_id', $landlord->id)
            ->where('event_type', 'complaint_submitted')
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertEquals('in_app', $notifications->first()->channel);

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    /**
     * Phase 7B: a failed email delivery must not block the complaint from
     * being recorded, must not throw back to the tenant, and must leave an
     * accurate 'failed' status on that one notification row — the in_app
     * row, a separate row entirely, is unaffected.
     */
    /**
     * G-1B: a generic exception during SMTP submission (here, a simulated
     * connection refusal) carries no SMTP response code and is therefore
     * genuinely ambiguous, not a confirmed failure — see
     * NotificationService::classifyMailException(). It's recorded as
     * delivery_status='submission_unknown' rather than status='failed';
     * the complaint submission itself must still never be blocked by it.
     */
    public function test_an_ambiguous_emergency_email_failure_does_not_block_submission_and_is_recorded_as_submission_unknown(): void
    {
        $this->seed();

        \Illuminate\Support\Facades\Mail::shouldReceive('to')->once()->andReturnSelf();
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->once()->andThrow(new \Exception('SMTP connection refused'));

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('category', 'structural')
            ->set('subject', 'Ceiling collapsing')
            ->set('description', 'Part of the ceiling has come down.')
            ->set('priority', 'emergency')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('complaints', ['subject' => 'Ceiling collapsing', 'priority' => 'emergency']);

        $inApp = AppNotification::where('notifiable_type', $landlord->getMorphClass())
            ->where('notifiable_id', $landlord->id)->where('channel', 'in_app')->where('event_type', 'complaint_submitted')->first();
        $email = AppNotification::where('notifiable_type', $landlord->getMorphClass())
            ->where('notifiable_id', $landlord->id)->where('channel', 'email')->where('event_type', 'complaint_submitted')->first();

        $this->assertEquals('sent', $inApp->status);
        // Ambiguous, not a confirmed failure — status keeps its old
        // meaning ('pending', which scopeNeedsAttention() still surfaces);
        // delivery_status is where the ambiguity is actually recorded.
        $this->assertEquals('pending', $email->status);
        $this->assertEquals('submission_unknown', $email->delivery_status);
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
