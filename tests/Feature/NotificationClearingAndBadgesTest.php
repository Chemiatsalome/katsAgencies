<?php

namespace Tests\Feature;

use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\DueDayChangeRequest;
use App\Models\House;
use App\Models\LateFeeWaiverRequest;
use App\Models\MaintenanceTask;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\TenancyChangeRequest;
use App\Models\TenancyRenewalRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepositService;
use App\Services\PendingActionsService;
use App\Services\TenancyChangeRequestService;
use App\Services\TenancyProvisioningService;
use App\Services\TenancyRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers two audit findings: (C1) deciding a notice/change-request/renewal,
 * or resolving a deposit dispute, or changing a maintenance task's status,
 * never cleared the landlord's own originating alert — the generic
 * notification bell stayed inflated by already-resolved items forever.
 * Fixed via NotificationService::markRelatedRead(), generalizing the
 * pattern Arrears\Index/Tenancies\Show already used for late-fee waivers
 * and due-day changes. The risky part of that fix is the notifiable filter:
 * several of these share one related model (e.g. a Tenancy) across many
 * event types and both directions, so clearing one party's alert must never
 * silently clear the other party's still-unread one. (C2-C4) three
 * previously un-badged pending actions — late-fee waiver requests, due-day
 * change requests, a contract awaiting signature — now have counts. Kept as
 * a permanent regression test since the notifiable-filter correctness here
 * has no other coverage in the suite.
 */
class NotificationClearingAndBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Badge Test Tenant',
            'phone' => '0700333222',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    private function makeDefaultTemplate(User $landlord): ContractTemplate
    {
        return ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Standard Agreement',
            'body' => "Tenant: {{tenant_name}}\nProperty: {{property_name}}\nUnit: {{unit_number}}\nRent: {{monthly_rent}}",
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_accepting_a_change_request_clears_the_landlords_alert_but_not_the_tenants_own(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $request = app(TenancyChangeRequestService::class)->create(
            $tenancy, $tenancy->tenant_id, 'monthly_rent', 'Please lower it', 'Hardship'
        );

        // A notification the tenant themselves received about something
        // else entirely must never be touched by the landlord's decision.
        $tenantsOwnNotification = AppNotification::create([
            'notifiable_type' => $tenancy->tenant->getMorphClass(), 'notifiable_id' => $tenancy->tenant->id,
            'channel' => 'in_app', 'event_type' => 'landlord_message', 'title' => 't', 'body' => 'b', 'status' => 'sent',
        ]);

        $landlordAlert = AppNotification::where('related_type', TenancyChangeRequest::class)
            ->where('related_id', $request->id)
            ->firstOrFail();
        $this->assertNull($landlordAlert->read_at);

        app(TenancyChangeRequestService::class)->decide($request, $landlord->id, 'accepted');

        $this->assertNotNull($landlordAlert->fresh()->read_at);
        $this->assertNull($tenantsOwnNotification->fresh()->read_at);
    }

    public function test_approving_a_renewal_request_clears_the_landlords_alert(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);
        $tenancy->update(['lease_end_date' => now()->addMonth()]);

        $request = app(TenancyRenewalService::class)->request($tenancy, $tenancy->tenant_id, 12, null, 'Please renew');

        $landlordAlert = AppNotification::where('related_type', TenancyRenewalRequest::class)
            ->where('related_id', $request->id)
            ->firstOrFail();

        app(TenancyRenewalService::class)->decide($request->fresh(), $landlord->id, 'approve');

        $this->assertNotNull($landlordAlert->fresh()->read_at);
    }

    public function test_tenant_accepting_alternative_renewal_terms_clears_their_own_alert_not_the_landlords(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);
        $tenancy->update(['lease_end_date' => now()->addMonth()]);

        $request = app(TenancyRenewalService::class)->request($tenancy, $tenancy->tenant_id, 12, null, null);
        app(TenancyRenewalService::class)->proposeAlternative($request->fresh(), $landlord->id, 6, null, 'Shorter term instead');

        $tenantAlert = AppNotification::where('related_type', TenancyRenewalRequest::class)
            ->where('related_id', $request->id)
            ->where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->firstOrFail();

        app(TenancyRenewalService::class)->respondToAlternative($request->fresh(), $tenancy->tenant_id, true);

        $this->assertNotNull($tenantAlert->fresh()->read_at);
    }

    public function test_resolving_a_disputed_deduction_clears_only_that_dispute_notification(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenancy->update(['deposit_paid' => 40000]);

        $deduction = app(DepositService::class)->recordDeduction($tenancy, 5000, 'Damage', now()->format('Y-m-d'), $landlord->id);
        app(DepositService::class)->disputeDeduction($deduction, 'Not my fault', $tenancy->tenant_id);

        $disputeAlert = AppNotification::where('related_type', Tenancy::class)
            ->where('related_id', $tenancy->id)
            ->where('event_type', 'deposit_deduction_disputed')
            ->firstOrFail();

        // A different, unrelated notification about the same tenancy must
        // survive — only the dispute-specific event_type should clear.
        $unrelatedTenancyAlert = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'tenant_paid_mpesa', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => Tenancy::class, 'related_id' => $tenancy->id,
        ]);

        app(DepositService::class)->resolveDispute($deduction->fresh(), 'uphold', $landlord->id);

        $this->assertNotNull($disputeAlert->fresh()->read_at);
        $this->assertNull($unrelatedTenancyAlert->fresh()->read_at);
    }

    public function test_landlord_reviewing_a_maintenance_task_clears_the_originating_complaint_notification(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $complaint = Complaint::create([
            'tenant_id' => $tenancy->tenant_id,
            'house_id' => $tenancy->house_id,
            'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaks',
            'category' => 'plumbing',
            'priority' => 'medium',
            'status' => 'submitted',
        ]);

        $complaintAlert = AppNotification::create([
            'notifiable_type' => $landlord->getMorphClass(), 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'complaint_submitted', 'title' => 't', 'body' => 'b', 'status' => 'sent',
            'related_type' => Complaint::class, 'related_id' => $complaint->id,
        ]);

        $task = MaintenanceTask::create([
            'property_id' => $tenancy->house->block->property_id,
            'house_id' => $tenancy->house_id,
            'complaint_id' => $complaint->id,
            'title' => $complaint->subject,
            'category' => 'plumbing',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $landlord->id,
        ]);

        Livewire::actingAs($landlord)->test(MaintenanceIndex::class)->call('markReviewed', $task->id);

        $this->assertNotNull($complaintAlert->fresh()->read_at);
    }

    public function test_late_fee_waiver_and_due_day_change_requests_are_counted_in_their_badges(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => $tenancy->monthly_rent,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id,
            'tenancy_id' => $tenancy->id,
            'requested_amount' => 500,
            'reason' => 'Mistake',
            'status' => 'pending',
        ]);

        DueDayChangeRequest::create([
            'tenancy_id' => $tenancy->id,
            'requested_due_day' => 15,
            'reason' => 'Payday changed',
            'status' => 'pending',
        ]);

        $service = app(PendingActionsService::class);
        $this->assertSame(1, $service->lateFeeWaiverRequestsAwaitingDecisionCount($landlord));
        $this->assertSame(1, $service->dueDayChangeRequestsAwaitingDecisionCount($landlord));
    }

    public function test_tenant_contract_awaiting_signature_is_badge_counted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;

        $service = app(PendingActionsService::class);
        $this->assertSame(0, $service->tenantContractAwaitingSignatureCount($tenant));

        Contract::create([
            'tenancy_id' => $tenancy->id,
            'source' => 'system_template',
            'status' => 'sent',
            'body_snapshot' => 'Agreement text',
            'version' => 1,
            'sent_at' => now(),
        ]);

        $this->assertSame(1, $service->tenantContractAwaitingSignatureCount($tenant));
    }
}
