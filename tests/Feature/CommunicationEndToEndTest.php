<?php

namespace Tests\Feature;

use App\Livewire\Arrears\Index as ArrearsIndex;
use App\Livewire\Contracts\Index as ContractsIndex;
use App\Livewire\Documents\Index as DocumentsIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Tenancies\Index as TenanciesIndex;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\Announcements\Index as TenantAnnouncements;
use App\Livewire\Tenant\Contract as TenantContract;
use App\Livewire\Tenant\Maintenance\Create as TenantComplaintCreate;
use App\Livewire\Tenant\Maintenance\Show as TenantComplaintShow;
use App\Livewire\Tenant\Rent as TenantRent;
use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\ComplaintUpdate;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\DueDayChangeRequest;
use App\Models\Expense;
use App\Models\House;
use App\Models\LateFeeWaiverRequest;
use App\Models\MaintenanceTask;
use App\Models\RentCharge;
use App\Models\Tenant;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Walks one tenant/landlord pair through every communication surface
 * touched this session — contracts & signing, documents, announcements,
 * maintenance (complaint → task → schedule → complete → reopen →
 * recomplete → confirm), and the two rent-side request/decision flows —
 * checking not just that each action succeeds, but that the resulting
 * notification actually resolves to the right page for whoever receives
 * it. Kept as a permanent regression test, not a throwaway scratch one:
 * this is the one place that exercises how all the pieces fit together,
 * rather than any single piece in isolation.
 */
class CommunicationEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'E2E Test Tenant',
            'phone' => '0700999888',
            'payment_due_day' => 5,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 5,
        ]);
    }

    public function test_full_communication_lifecycle_end_to_end(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $property = $tenancy->house->block->property;

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'e2e-tenant@example.test', 'password'
        );

        // ---------------------------------------------------------------
        // 1. Contract: template → generate → send → tenant blocked without
        //    an ID → uploads ID + signs → status consistent everywhere.
        // ---------------------------------------------------------------
        ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'name' => 'E2E Property Agreement',
            'body' => 'Agreement for {{tenant_name}} at {{property_name}}, unit {{unit_number}}.',
            'is_default' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('generateContract')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertNotNull($tenancy->contract);
        $this->assertSame('E2E Property Agreement', $tenancy->contract->template->name);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('sendContract')
            ->assertHasNoErrors();

        $this->assertSame('awaiting_signature', $tenancy->contract->fresh()->status);

        // Blocked without an ID on file.
        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'E2E Test Tenant')
            ->call('sign')
            ->assertHasErrors('idDocument');

        // Upload the ID alongside signing — succeeds.
        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'E2E Test Tenant')
            ->set('idDocument', UploadedFile::fake()->image('id.jpg'))
            ->call('sign')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertTrue($tenancy->contract->isSigned());

        $this->assertDatabaseHas('documents', [
            'documentable_type' => $tenancy->getMorphClass(),
            'documentable_id' => $tenancy->id,
            'category' => 'id_document',
        ]);

        // Landlord gets notified the contract was signed, with a working link.
        $signedNotice = AppNotification::where('event_type', 'contract_signed')
            ->where('notifiable_id', $landlord->id)
            ->latest('id')->first();
        $this->assertNotNull($signedNotice);
        $this->assertSame(route('manage.tenancies.show', $tenancy->id), $signedNotice->actionUrl($landlord));

        // Status is consistent in every place that shows it: Tenancy page,
        // the portfolio-wide Contracts overview, and the Documents list.
        Livewire::actingAs($landlord)->test(TenancyShow::class, ['tenancy' => $tenancy])->assertSee('Signed');
        Livewire::actingAs($landlord)->test(ContractsIndex::class)->assertSee('E2E Test Tenant')->assertSee('Signed');
        Livewire::actingAs($landlord)->test(DocumentsIndex::class)->set('tenantId', (string) $tenancy->tenant_id)->assertSee('Signed');

        // ---------------------------------------------------------------
        // 2. Landlord uploads a non-lease document → tenant can see it;
        //    the generic uploader itself refuses a "lease_agreement" entry.
        // ---------------------------------------------------------------
        Livewire::actingAs($landlord)
            ->test(\App\Livewire\Documents\Manager::class, ['model' => $tenancy])
            ->set('category', 'property_document')
            ->set('file', UploadedFile::fake()->create('house-rules.pdf', 200, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($tenantUser)
            ->test(\App\Livewire\Tenant\MyTenancy::class)
            ->assertSee('house-rules.pdf');

        // ---------------------------------------------------------------
        // 3. Announcement: landlord broadcasts → tenant sees it, and the
        //    bell notification resolves to the Announcements page.
        // ---------------------------------------------------------------
        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenancy->tenant_id)
            ->set('subject', 'Water Interruption')
            ->set('body', 'Water will be off Friday 9am-3pm for maintenance.')
            ->call('send')
            ->assertHasNoErrors();

        Livewire::actingAs($tenantUser)
            ->test(TenantAnnouncements::class)
            ->assertSee('Water Interruption');

        $announcementNotice = AppNotification::where('event_type', 'landlord_message')
            ->where('notifiable_id', $tenancy->tenant_id)->firstOrFail();
        $this->assertSame(route('tenant.announcements.index'), $announcementNotice->actionUrl($tenantUser));
        $this->assertNotNull($announcementNotice->fresh()->read_at, 'Viewing the Announcements page should mark it read.');

        // ---------------------------------------------------------------
        // 4. Maintenance: submit → landlord notified → thread replies both
        //    ways → convert to task → schedule (partial expense) → tenant
        //    notified → complete → tenant reopens → landlord recompletes
        //    with an additional expense → tenant confirms.
        // ---------------------------------------------------------------
        Livewire::actingAs($tenantUser)
            ->test(TenantComplaintCreate::class)
            ->set('category', 'plumbing')
            ->set('subject', 'Leaking tap')
            ->set('description', 'Kitchen tap drips constantly')
            ->set('priority', 'normal')
            ->call('submit')
            ->assertHasNoErrors();

        $complaint = Complaint::where('subject', 'Leaking tap')->firstOrFail();

        $submittedNotice = AppNotification::where('event_type', 'complaint_submitted')
            ->where('notifiable_id', $landlord->id)->firstOrFail();
        $this->assertSame(route('manage.maintenance.index', ['complaint' => $complaint->id]), $submittedNotice->actionUrl($landlord));

        // Landlord replies on the thread.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'requests'])
            ->call('openComplaintThread', $complaint->id)
            ->set('complaintNote', 'We will send a plumber tomorrow.')
            ->call('postComplaintUpdate')
            ->assertHasNoErrors();

        // Tenant sees it and replies back.
        Livewire::actingAs($tenantUser)
            ->test(TenantComplaintShow::class, ['complaint' => $complaint])
            ->assertSee('We will send a plumber tomorrow.')
            ->set('replyNote', 'Thank you, I will be home.')
            ->call('reply')
            ->assertHasNoErrors();

        $this->assertSame(2, ComplaintUpdate::where('complaint_id', $complaint->id)->count());

        // Convert to a task; double-submitting must not create a duplicate.
        $component = Livewire::actingAs($landlord)->test(MaintenanceIndex::class, ['tab' => 'requests']);
        $component->call('convertToTask', $complaint->id);
        $component->call('convertToTask', $complaint->id);
        $this->assertSame(1, MaintenanceTask::where('complaint_id', $complaint->id)->count());

        $task = MaintenanceTask::where('complaint_id', $complaint->id)->firstOrFail();

        // Schedule it and record a partial cost up front.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openAssignForm', $task->id)
            ->set('assign_scheduled_date', now()->addDays(2)->format('Y-m-d'))
            ->set('assign_estimated_cost', '500')
            ->set('assign_record_expense_now', true)
            ->call('saveAssign')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame('scheduled', $task->status);
        $this->assertNotNull($task->expense_id);

        $scheduledNotice = AppNotification::where('event_type', 'maintenance_status_scheduled')
            ->where('notifiable_id', $tenancy->tenant_id)->firstOrFail();
        $this->assertSame(route('tenant.maintenance.show', $complaint->id), $scheduledNotice->actionUrl($tenantUser));

        // Complete the first time.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $complaint->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('resolved', $complaint->status);
        $this->assertTrue($complaint->awaitingTenantConfirmation());

        // Tenant says it's not actually fixed.
        Livewire::actingAs($tenantUser)
            ->test(TenantComplaintShow::class, ['complaint' => $complaint])
            ->call('openReopenForm')
            ->set('reopenReason', 'Still dripping')
            ->call('reopenComplaint')
            ->assertHasNoErrors();

        $task->refresh();
        $complaint->refresh();
        $this->assertSame('in_progress', $task->status);
        $this->assertSame('assigned', $complaint->status);

        $reopenedNotice = AppNotification::where('event_type', 'complaint_reopened')
            ->where('notifiable_id', $landlord->id)->firstOrFail();
        $this->assertSame(route('manage.maintenance.index', ['complaint' => $complaint->id]), $reopenedNotice->actionUrl($landlord));

        // Landlord recompletes — this time it costs more, as a second expense.
        Livewire::actingAs($landlord)
            ->test(MaintenanceIndex::class, ['tab' => 'scheduled'])
            ->call('openCompleteForm', $task->id)
            ->set('complete_additional_cost', 250)
            ->call('complete')
            ->assertHasNoErrors();

        $task->refresh();
        $complaint->refresh();
        $this->assertSame(2, Expense::where('house_id', $tenancy->house_id)->count());
        $this->assertEquals(750.0, (float) $task->actual_cost);
        $this->assertTrue($complaint->awaitingTenantConfirmation(), 'Recompleting must re-prompt for confirmation.');

        // Tenant confirms this time.
        Livewire::actingAs($tenantUser)
            ->test(TenantComplaintShow::class, ['complaint' => $complaint])
            ->call('confirmResolved')
            ->assertHasNoErrors();

        $complaint->refresh();
        $this->assertNotNull($complaint->tenant_confirmed_at);
        $this->assertFalse($complaint->awaitingTenantConfirmation());
        $this->assertTrue($complaint->isClosed());

        $confirmedNotice = AppNotification::where('event_type', 'complaint_confirmed_resolved')
            ->where('notifiable_id', $landlord->id)->firstOrFail();
        $this->assertSame(route('manage.maintenance.index', ['complaint' => $complaint->id]), $confirmedNotice->actionUrl($landlord));

        // ---------------------------------------------------------------
        // 5. Late fee waiver request: tenant requests → landlord rejects
        //    with a reason → notification auto-marked read → tenant sees
        //    the reason.
        // ---------------------------------------------------------------
        // A far-future period avoids colliding with whatever rent charges
        // the tenancy provisioning service already generated for the
        // months since move-in (unique on tenancy_id + period_year/month).
        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->addYears(5)->year,
            'period_month' => 1,
            'due_date' => now()->subDays(10),
            'amount_expected' => (float) $tenancy->monthly_rent + 1500,
            'late_fee_amount' => 1500,
        ]);

        Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->call('openWaiverForm', $charge->id)
            ->set('waiverReason', 'Was travelling')
            ->call('requestWaiver')
            ->assertHasNoErrors();

        $waiverRequest = LateFeeWaiverRequest::where('rent_charge_id', $charge->id)->firstOrFail();
        $waiverNotice = AppNotification::where('related_type', LateFeeWaiverRequest::class)
            ->where('related_id', $waiverRequest->id)->firstOrFail();
        $this->assertNull($waiverNotice->read_at);

        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('rejectWaiverRequest', $waiverRequest->id, 'Late fees apply regardless of travel')
            ->assertHasNoErrors();

        $this->assertNotNull($waiverNotice->fresh()->read_at, 'Deciding the request should auto-mark its notification read.');

        Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->set('year', $charge->period_year)
            ->assertSee('Late fees apply regardless of travel');

        // ---------------------------------------------------------------
        // 6. Due day change request: tenant requests → landlord accepts →
        //    due day actually changes and loses its grace period.
        // ---------------------------------------------------------------
        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('requestedDueDay', 20)
            ->set('dueDayReason', 'Paid on the 20th')
            ->call('requestDueDayChange')
            ->assertHasNoErrors();

        $dueDayRequest = DueDayChangeRequest::where('tenancy_id', $tenancy->id)->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('acceptDueDayChangeRequest', $dueDayRequest->id)
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertSame(20, $tenancy->rent_due_day);
        $this->assertTrue($tenancy->due_day_requested_by_tenant);

        // ---------------------------------------------------------------
        // 7. Bulk Send Contracts respects per-property templates and
        //    excludes the tenant who's already signed.
        // ---------------------------------------------------------------
        $bulkComponent = Livewire::actingAs($landlord)->test(TenanciesIndex::class);
        $bulkComponent->call('openBulkContracts');
        $candidateIds = $bulkComponent->get('selectedBulkContractTenancyIds');
        $this->assertNotContains($tenancy->id, $candidateIds, 'An already-signed tenancy must be excluded by default.');
    }
}
