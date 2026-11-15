<?php

namespace Tests\Feature;

use App\Console\Commands\SendContractExpiryAlerts;
use App\Console\Commands\SendTenancyRenewalReminders;
use App\Livewire\Tenant\Contract as TenantContract;
use App\Livewire\Tenant\Maintenance\Create as TenantMaintenanceCreate;
use App\Livewire\Tenant\Maintenance\Show as TenantMaintenanceShow;
use App\Livewire\Tenant\Profile as TenantProfile;
use App\Livewire\TenantInvitations\Accept;
use App\Models\AppNotification;
use App\Models\Complaint;
use App\Models\ContractTemplate;
use App\Models\House;
use App\Models\Manager;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ContractService;
use App\Services\NotificationService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Closes the cross-role audit's F-1 finding (managers received zero
 * proactive notifications) and its own follow-on discovery (the bell's
 * scopeForLandlordContext() unintentionally surfaced every landlord-
 * addressed notification, including financial ones, to any manager of
 * that landlord regardless of property or permission). Covers the shared
 * architecture (Manager::scopeEligibleForProperty(),
 * NotificationService::notifyPropertyStakeholders(), the fixed bell scope)
 * plus a representative real call site end-to-end, rather than only the
 * primitives in isolation.
 */
class ManagerNotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions, bool $allProperties, array $properties = [], string $status = 'active'): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Routing Test Manager {$n}",
            'phone' => '07140000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "routing-test-manager-{$n}@example.test",
            'all_properties' => $allProperties,
            'status' => $status,
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => $status === 'active' ? 'active' : 'inactive',
        ]);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions($permissions);
        $manager->update(['user_id' => $managerUser->id]);

        if (! $allProperties && $properties) {
            $manager->properties()->sync(collect($properties)->pluck('id'));
        }

        return $managerUser->fresh();
    }

    // --- Manager::scopeEligibleForProperty() -------------------------------------------

    public function test_eligible_for_property_includes_an_all_properties_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, [], allProperties: true);

        $this->assertTrue(Manager::eligibleForProperty($property)->get()->pluck('user_id')->contains($managerUser->id));
    }

    public function test_eligible_for_property_includes_a_manager_specifically_assigned_to_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, [], allProperties: false, properties: [$property]);

        $this->assertTrue(Manager::eligibleForProperty($property)->get()->pluck('user_id')->contains($managerUser->id));
    }

    public function test_eligible_for_property_excludes_a_manager_assigned_to_a_different_property(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $properties = $landlord->properties()->take(2)->get();
        $this->assertGreaterThanOrEqual(2, $properties->count(), 'Seed data should include at least two properties for this landlord.');

        $managerUser = $this->makeManager($landlord, [], allProperties: false, properties: [$properties[1]]);

        $this->assertFalse(Manager::eligibleForProperty($properties[0])->get()->pluck('user_id')->contains($managerUser->id));
    }

    public function test_eligible_for_property_excludes_a_deactivated_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, [], allProperties: true, status: 'inactive');

        $this->assertFalse(Manager::eligibleForProperty($property)->get()->pluck('user_id')->contains($managerUser->id));
    }

    // --- NotificationService::notifyPropertyStakeholders() ----------------------------

    public function test_notify_property_stakeholders_notifies_landlord_and_permitted_manager_but_not_unpermitted_or_unassigned_ones(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $properties = $landlord->properties()->take(2)->get();
        $property = $properties[0];

        $permittedManager = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);
        $unpermittedManager = $this->makeManager($landlord, [], allProperties: false, properties: [$property]);
        $unassignedManager = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$properties[1]]);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property,
            'TEST_stakeholder_event',
            'Test Title',
            'Test body',
            ['in_app'],
            null,
            'complaints.view',
        );

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'TEST_stakeholder_event',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $permittedManager->id, 'event_type' => 'TEST_stakeholder_event',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => 'TEST_stakeholder_event',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unassignedManager->id, 'event_type' => 'TEST_stakeholder_event',
        ]);
    }

    public function test_notify_property_stakeholders_with_no_permission_gate_reaches_every_eligible_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, [], allProperties: true);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property, 'TEST_ungated_event', 'Test', 'Test', ['in_app'],
        );

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerUser->id, 'event_type' => 'TEST_ungated_event',
        ]);
    }

    // --- The fixed bell scope: closes the financial-exposure leak ---------------------

    /**
     * Regression test for the discovery made while building this
     * architecture: scopeForLandlordContext() used to substitute
     * landlordId() for a manager viewer, which matched every notification
     * ever addressed to the landlord directly — confirmed, before this
     * fix, to include a deposit-refund-style notification and to reach a
     * manager assigned to zero properties.
     */
    public function test_a_manager_never_sees_a_notification_addressed_directly_to_the_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $sensitiveNotification = AppNotification::create([
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'deposit_refunded', 'title' => 'Deposit refund', 'body' => 'KSh 10,000 refunded.',
            'status' => 'sent',
        ]);

        // Deliberately unassigned to any property — if this manager can
        // see it, no property-scoping is happening at all.
        $managerUser = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false);

        $this->assertFalse(
            AppNotification::forLandlordContext($managerUser)->whereKey($sensitiveNotification->id)->exists(),
            'A manager must never see a notification addressed directly to the landlord.'
        );
    }

    public function test_the_landlord_still_sees_their_own_notifications_in_their_own_bell(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $notification = AppNotification::create([
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id,
            'channel' => 'in_app', 'event_type' => 'deposit_refunded', 'title' => 'Deposit refund', 'body' => 'KSh 10,000 refunded.',
            'status' => 'sent',
        ]);

        $this->assertTrue(AppNotification::forLandlordContext($landlord)->whereKey($notification->id)->exists());
    }

    public function test_an_eligible_manager_does_see_a_notification_addressed_directly_to_them(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property, 'TEST_own_bell_event', 'Test', 'Test', ['in_app'], null, 'complaints.view',
        );

        $this->assertTrue(
            AppNotification::forLandlordContext($managerUser)->where('event_type', 'TEST_own_bell_event')->exists()
        );
    }

    // --- Real call sites, end-to-end ----------------------------------------------------

    public function test_a_tenant_maintenance_request_reaches_the_landlord_and_an_eligible_manager_but_not_an_unpermitted_one(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);
        $unpermittedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);

        $tenantUser = $tenancy->tenant->user ?? tap(User::factory()->create(), function ($u) use ($tenancy) {
            $u->assignRole('tenant');
            $tenancy->tenant->update(['user_id' => $u->id]);
        });

        Livewire::actingAs($tenantUser)
            ->test(TenantMaintenanceCreate::class)
            ->set('subject', 'Leaking tap')
            ->set('description', 'The kitchen tap has been leaking for two days.')
            ->set('priority', 'normal')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'complaint_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id, 'event_type' => 'complaint_submitted',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => 'complaint_submitted',
        ]);
    }

    public function test_tenant_registration_completion_reaches_the_landlord_even_when_a_manager_sent_the_invitation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = \App\Models\House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();
        $property = $house->block->property;

        $invitingManager = $this->makeManager($landlord, ['tenancies.create', 'tenancies.view'], allProperties: false, properties: [$property]);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $invitingManager->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 15000,
            'deposit_required' => 15000,
            'rent_due_day' => 5,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'New Routing Tenant')
            ->set('phone', '0711000777')
            ->set('email', 'new.routing.tenant@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        // The landlord must find out even though they never sent the
        // invitation themselves — previously this notified only whoever
        // the invitedBy column pointed to, so a manager-sent invitation
        // left the landlord with no record of it at all.
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'tenant_registered',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $invitingManager->id, 'event_type' => 'tenant_registered',
        ]);
    }

    public function test_a_tenant_signing_their_contract_reaches_the_landlord_and_a_manager_with_manage_contracts_permission(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Standard Agreement',
            'body' => "Tenant: {{tenant_name}}\nProperty: {{property_name}}\nUnit: {{unit_number}}\nRent: {{monthly_rent}}",
            'is_default' => true,
            'is_active' => true,
        ]);

        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();
        $tenant = Tenant::create(['name' => 'Routing Contract Tenant', 'phone' => '0700111333', 'payment_due_day' => 1]);
        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);

        $contractManager = $this->makeManager($landlord, ['tenancies.manage_contracts'], allProperties: false, properties: [$property]);
        $unrelatedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenant, $tenant->name, 'routing-contract-tenant@example.test', 'password');
        app(ContractService::class)->markSent($tenancy->contract);

        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'Routing Contract Tenant')
            ->set('idDocument', UploadedFile::fake()->image('id.jpg'))
            ->call('sign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'contract_signed',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $contractManager->id, 'event_type' => 'contract_signed',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unrelatedManager->id, 'event_type' => 'contract_signed',
        ]);
    }

    // --- Complaint lifecycle (Tenant\Maintenance\Show) — closes the coverage-audit's C-1 finding ----

    private function makeComplaintTenancy(User $landlord): array
    {
        static $n = 0;
        $n++;

        $house = House::vacant()->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->whereDoesntHave('tenancies', fn ($q) => $q->whereIn('status', Tenancy::OCCUPYING_STATUSES))
            ->inRandomOrder()->firstOrFail();
        $tenant = Tenant::create(['name' => "Complaint Routing Tenant {$n}", 'phone' => '070022'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'payment_due_day' => 1]);
        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenant, $tenant->name, "complaint-routing-tenant-{$n}@example.test", 'password');

        return [$tenancy, $tenantUser];
    }

    public function test_a_complaint_reply_reaches_the_landlord_and_an_eligible_manager_but_not_others(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $tenantUser] = $this->makeComplaintTenancy($landlord);
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);
        $unpermittedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);

        $complaint = Complaint::create([
            'tenant_id' => $tenancy->tenant_id, 'house_id' => $tenancy->house_id, 'property_id' => $property->id,
            'tenancy_id' => $tenancy->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Still leaking', 'priority' => 'medium', 'status' => 'submitted',
        ]);

        Livewire::actingAs($tenantUser)
            ->test(TenantMaintenanceShow::class, ['complaint' => $complaint])
            ->set('replyNote', 'Any update on this?')
            ->call('reply')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'complaint_update_posted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id, 'event_type' => 'complaint_update_posted',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => 'complaint_update_posted',
        ]);
    }

    /**
     * Covers the remaining three complaint-lifecycle events without
     * repeating the full property/permission matrix already proven above
     * — each just confirms the eligible manager actually receives that
     * specific event type, through the real Livewire action.
     */
    public function test_the_remaining_complaint_lifecycle_events_each_reach_an_eligible_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        // complaint_confirmed_resolved
        [$tenancyA, $tenantUserA] = $this->makeComplaintTenancy($landlord);
        $managerA = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$tenancyA->house->block->property]);
        $complaintA = Complaint::create([
            'tenant_id' => $tenancyA->tenant_id, 'house_id' => $tenancyA->house_id, 'property_id' => $tenancyA->house->block->property_id,
            'tenancy_id' => $tenancyA->id, 'category' => 'plumbing', 'subject' => 'Resolved issue',
            'description' => 'Fixed', 'priority' => 'medium', 'status' => 'resolved',
        ]);
        Livewire::actingAs($tenantUserA)->test(TenantMaintenanceShow::class, ['complaint' => $complaintA])
            ->call('confirmResolved')->assertHasNoErrors();
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerA->id, 'event_type' => 'complaint_confirmed_resolved',
        ]);

        // complaint_submitted (recurrence)
        [$tenancyB, $tenantUserB] = $this->makeComplaintTenancy($landlord);
        $managerB = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$tenancyB->house->block->property]);
        $complaintB = Complaint::create([
            'tenant_id' => $tenancyB->tenant_id, 'house_id' => $tenancyB->house_id, 'property_id' => $tenancyB->house->block->property_id,
            'tenancy_id' => $tenancyB->id, 'category' => 'plumbing', 'subject' => 'Closed issue',
            'description' => 'Was fixed', 'priority' => 'medium', 'status' => 'closed', 'tenant_confirmed_at' => now(),
        ]);
        Livewire::actingAs($tenantUserB)->test(TenantMaintenanceShow::class, ['complaint' => $complaintB])
            ->set('recurringNote', 'It came back')
            ->call('reportRecurringIssue');
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerB->id, 'event_type' => 'complaint_submitted',
        ]);

        // complaint_reopened
        [$tenancyC, $tenantUserC] = $this->makeComplaintTenancy($landlord);
        $managerC = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$tenancyC->house->block->property]);
        $complaintC = Complaint::create([
            'tenant_id' => $tenancyC->tenant_id, 'house_id' => $tenancyC->house_id, 'property_id' => $tenancyC->house->block->property_id,
            'tenancy_id' => $tenancyC->id, 'category' => 'plumbing', 'subject' => 'Not actually fixed',
            'description' => 'Still broken', 'priority' => 'medium', 'status' => 'resolved',
        ]);
        Livewire::actingAs($tenantUserC)->test(TenantMaintenanceShow::class, ['complaint' => $complaintC])
            ->set('reopenReason', 'Still leaking')
            ->call('reopenComplaint')->assertHasNoErrors();
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerC->id, 'event_type' => 'complaint_reopened',
        ]);
    }

    // --- Scheduled renewal reminders and contract-expiry alerts ------------------------

    public function test_scheduled_renewal_reminder_reaches_an_eligible_manager_and_is_not_duplicated_on_rerun(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['lease_end_date' => now()->addDays(29)->format('Y-m-d')]);
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, ['tenancies.view'], allProperties: false, properties: [$property]);
        $unpermittedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);
        $unrelatedManager = $this->makeManager($landlord, ['tenancies.view'], allProperties: false, properties: [
            $landlord->properties()->where('id', '!=', $property->id)->first() ?? $landlord->properties()->create(['name' => 'Unrelated', 'type' => 'apartment', 'status' => 'active']),
        ]);

        $this->artisan('notifications:renewal-reminders')->run();

        $eventType = 'tenancy_renewal_reminder_'.$tenancy->renewalReviewDueDate()->format('Ym');

        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => $eventType]);
        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => Tenant::class, 'notifiable_id' => $tenancy->tenant_id, 'event_type' => $eventType]);
        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id, 'event_type' => $eventType]);
        $this->assertDatabaseMissing('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => $eventType]);
        $this->assertDatabaseMissing('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $unrelatedManager->id, 'event_type' => $eventType]);

        $countBefore = AppNotification::where('event_type', $eventType)->count();
        $this->artisan('notifications:renewal-reminders')->run();
        $this->assertEquals($countBefore, AppNotification::where('event_type', $eventType)->count(), 'Re-running the command must not create duplicate notifications.');
    }

    public function test_scheduled_contract_expiry_alert_reaches_an_eligible_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['lease_end_date' => now()->startOfDay()->addDays(7)->format('Y-m-d')]);
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, ['tenancies.view'], allProperties: false, properties: [$property]);
        $unpermittedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);

        $this->artisan('notifications:contract-expiry')->run();

        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'contract_expiry_7']);
        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => Tenant::class, 'notifiable_id' => $tenancy->tenant_id, 'event_type' => 'contract_expiry_7']);
        $this->assertDatabaseHas('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id, 'event_type' => 'contract_expiry_7']);
        $this->assertDatabaseMissing('app_notifications', ['notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => 'contract_expiry_7']);
    }

    // --- Tenant profile updates ---------------------------------------------------------

    public function test_a_tenant_profile_update_reaches_the_landlord_and_an_eligible_manager_but_not_others(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $tenantUser] = $this->makeComplaintTenancy($landlord);
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, ['tenants.view'], allProperties: false, properties: [$property]);
        $unassignedManager = $this->makeManager($landlord, ['tenants.view'], allProperties: false, properties: []);
        $unpermittedManager = $this->makeManager($landlord, ['tenancies.terminate'], allProperties: false, properties: [$property]);

        Livewire::actingAs($tenantUser)
            ->test(TenantProfile::class)
            ->set('phone', '0722999888')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'tenant_profile_updated',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id, 'event_type' => 'tenant_profile_updated',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unassignedManager->id, 'event_type' => 'tenant_profile_updated',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unpermittedManager->id, 'event_type' => 'tenant_profile_updated',
        ]);
    }

    // --- Mid-stream assignment revocation and multiple eligible managers ---------------

    /**
     * Distinct from "never assigned" (already covered elsewhere): this
     * manager genuinely WAS eligible and received a real notification,
     * then had that exact property unassigned, and must stop receiving
     * anything further for it — proves scopeEligibleForProperty() is
     * re-evaluated fresh on every notification, never cached from an
     * earlier eligible state.
     */
    public function test_revoking_a_managers_property_assignment_stops_further_notifications_for_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property, 'TEST_before_revocation', 'Test', 'Test', ['in_app'], null, 'complaints.view',
        );
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerUser->id, 'event_type' => 'TEST_before_revocation',
        ]);

        Manager::where('user_id', $managerUser->id)->first()->properties()->detach($property->id);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property, 'TEST_after_revocation', 'Test', 'Test', ['in_app'], null, 'complaints.view',
        );
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $managerUser->id, 'event_type' => 'TEST_after_revocation',
        ]);
    }

    public function test_two_eligible_managers_each_receive_exactly_one_notification_and_the_landlord_exactly_one(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerOne = $this->makeManager($landlord, ['complaints.view'], allProperties: false, properties: [$property]);
        $managerTwo = $this->makeManager($landlord, ['complaints.view'], allProperties: true);

        app(NotificationService::class)->notifyPropertyStakeholders(
            $property, 'TEST_multiple_managers', 'Test', 'Test', ['in_app'], null, 'complaints.view',
        );

        $this->assertEquals(1, AppNotification::where('event_type', 'TEST_multiple_managers')
            ->where('notifiable_type', User::class)->where('notifiable_id', $landlord->id)->count());
        $this->assertEquals(1, AppNotification::where('event_type', 'TEST_multiple_managers')
            ->where('notifiable_type', User::class)->where('notifiable_id', $managerOne->id)->count());
        $this->assertEquals(1, AppNotification::where('event_type', 'TEST_multiple_managers')
            ->where('notifiable_type', User::class)->where('notifiable_id', $managerTwo->id)->count());
        $this->assertEquals(3, AppNotification::where('event_type', 'TEST_multiple_managers')->count());
    }
}
