<?php

namespace Tests\Feature;

use App\Livewire\Applications\Index as ApplicationsIndex;
use App\Livewire\Deposits\RecordDeposit;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Tenancies\Index as TenanciesIndex;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Models\DepositPayment;
use App\Models\Document;
use App\Models\Manager;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers two audit findings fixed together: (B1) the tenants/tenancies/
 * applications/maintenance "view" manager permissions were enforced only in
 * the sidebar, never server-side, so a denied manager could still reach each
 * page by direct URL; (B2) DocumentPolicy compared a resolved landlord_id
 * straight against $user->id, which is never true for a manager, so every
 * manager was silently denied document view/delete regardless of their real
 * permissions. Kept as a permanent regression test — a security/
 * access-control fix with no other coverage in the suite.
 */
class ManagerPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions, bool $allProperties = true): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Test Manager {$n}",
            'phone' => '070000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "test-manager-{$n}@example.test",
            'all_properties' => $allProperties,
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions($permissions);
        $manager->update(['user_id' => $managerUser->id]);

        return $managerUser->fresh();
    }

    public function test_manager_without_tenants_view_permission_is_blocked_from_the_tenants_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenancies.view']);

        Livewire::actingAs($managerUser)->test(TenantsIndex::class)->assertStatus(403);
    }

    public function test_manager_with_tenants_view_permission_can_view_the_tenants_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(TenantsIndex::class)->assertStatus(200);
    }

    public function test_manager_without_tenancies_view_permission_is_blocked_from_the_tenancies_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(TenanciesIndex::class)->assertStatus(403);
    }

    public function test_manager_without_applications_view_permission_is_blocked_from_the_applications_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(ApplicationsIndex::class)->assertStatus(403);
    }

    public function test_manager_without_maintenance_or_complaints_view_permission_is_blocked_from_the_maintenance_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(MaintenanceIndex::class)->assertStatus(403);
    }

    public function test_manager_with_only_complaints_view_permission_can_still_reach_the_maintenance_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        // The page covers both tasks and complaints — either view permission
        // is enough to reach it, not just maintenance.view.
        $managerUser = $this->makeManager($landlord, ['complaints.view']);

        Livewire::actingAs($managerUser)->test(MaintenanceIndex::class)->assertStatus(200);
    }

    public function test_manager_assigned_to_the_documents_property_can_delete_a_document_there(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, ['properties.edit'], allProperties: false);
        $managerUser->manager->properties()->attach($property->id);

        $document = Document::create([
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => 'other',
            'original_filename' => 'title-deed.pdf',
            'path' => 'documents/title-deed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'uploaded_by' => $landlord->id,
        ]);

        Livewire::actingAs($managerUser)
            ->test(\App\Livewire\Documents\Index::class)
            ->call('delete', $document->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_manager_not_assigned_to_the_documents_property_cannot_delete_a_document_there(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        // Scoped to no properties at all — access control must still deny
        // this manager, not just "every manager" as the old bug did.
        $managerUser = $this->makeManager($landlord, ['properties.edit'], allProperties: false);

        $document = Document::create([
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => 'other',
            'original_filename' => 'title-deed.pdf',
            'path' => 'documents/title-deed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'uploaded_by' => $landlord->id,
        ]);

        Livewire::actingAs($managerUser)
            ->test(\App\Livewire\Documents\Index::class)
            ->call('delete', $document->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    /**
     * Regression tests for the confirmed High-severity defect from the
     * Manager/Agent/Deposit follow-up audit: a manager holding only
     * tenancies.terminate (an ordinary tenancy-management permission, used
     * for every other action in Tenancies\Show) could also reach every
     * deposit-financial action there, since they all relied solely on
     * TenancyPolicy::update(). Each of these six now requires
     * hasRole('landlord') via Tenancies\Show::authorizeDepositFinance() —
     * see that method's own docblock. Each test checks the database state
     * too, not just the 403 — a 403 that still partially wrote would be
     * just as real a problem.
     */
    public function test_manager_with_only_terminate_permission_cannot_record_a_deposit_refund(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 10000, 'deposit_paid' => 10000])->save();

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('refund_amount', 5000)
            ->set('refund_date', now()->format('Y-m-d'))
            ->call('recordRefund')
            ->assertForbidden();

        $this->assertDatabaseMissing('deposit_refunds', ['tenancy_id' => $tenancy->id]);
    }

    public function test_manager_with_only_terminate_permission_cannot_record_a_deposit_deduction(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 10000, 'deposit_paid' => 10000])->save();

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('deduction_amount', 1000)
            ->set('deduction_reason', 'Cleaning costs')
            ->set('deduction_date', now()->format('Y-m-d'))
            ->call('recordDeduction')
            ->assertForbidden();

        $this->assertDatabaseMissing('deposit_deductions', ['tenancy_id' => $tenancy->id]);
    }

    public function test_manager_with_only_terminate_permission_cannot_void_a_deposit_deduction(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 10000, 'deposit_paid' => 10000])->save();

        $deduction = app(DepositService::class)->recordDeduction($tenancy, 1000, 'Cleaning costs', now()->format('Y-m-d'), $landlord->id);

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('voidingDeductionId', $deduction->id)
            ->set('void_reason', 'Recorded in error')
            ->call('voidDeduction')
            ->assertForbidden();

        $this->assertNull($deduction->fresh()->voided_at);
    }

    public function test_manager_with_only_terminate_permission_cannot_resolve_a_deposit_dispute(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 10000, 'deposit_paid' => 10000])->save();

        $deduction = app(DepositService::class)->recordDeduction($tenancy, 1000, 'Cleaning costs', now()->format('Y-m-d'), $landlord->id);
        $deduction->forceFill([
            'disputed_at' => now(),
            'dispute_reason' => 'Not fair',
            'disputed_by' => $tenancy->tenant->user_id ?? $landlord->id,
        ])->save();

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('resolveDispute', $deduction->id, 'uphold')
            ->assertForbidden();

        $this->assertNull($deduction->fresh()->dispute_resolved_at);
    }

    public function test_manager_with_only_terminate_permission_cannot_confirm_a_deposit_payment(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmDepositPayment', $payment->id)
            ->assertForbidden();

        $this->assertEquals('awaiting_landlord_confirmation', $payment->fresh()->status);
    }

    public function test_manager_with_only_terminate_permission_cannot_dispute_a_deposit_payment(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('disputeDepositPayment', $payment->id, 'Amount looks wrong')
            ->assertForbidden();

        $this->assertEquals('awaiting_landlord_confirmation', $payment->fresh()->status);
    }

    /**
     * Guards against the fix accidentally turning the whole tenancy module
     * landlord-only: tenancies.terminate must keep working for what it's
     * actually meant for (ending a tenancy), and the rent-side
     * confirmPayment()/disputePayment() — which never touched deposits in
     * the first place, see Tenancies\Show's own method bodies — must stay
     * exactly as available to a manager as before this fix.
     */
    public function test_manager_with_terminate_permission_can_still_confirm_a_rent_payment_and_end_a_tenancy(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 20000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'bank', 'source' => 'tenant_submitted_offline',
            'status' => 'awaiting_landlord_confirmation', 'recorded_by' => $tenancy->tenant->user_id ?? $landlord->id,
        ]);

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmPayment', $payment->id)
            ->assertHasNoErrors();

        $this->assertEquals('confirmed', $payment->fresh()->status);

        Livewire::actingAs($managerUser)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'completed')
            ->set('actualMoveOutDate', now()->format('Y-m-d'))
            ->set('acknowledgeUnpaidBalance', true)
            ->call('reviewTermination')
            ->assertHasNoErrors()
            ->call('confirmTermination')
            ->assertHasNoErrors();

        $this->assertEquals('completed', $tenancy->fresh()->status);
    }

    /**
     * Follow-up regression test: Deposits\RecordDeposit (the nested
     * component Tenancies\Show embeds for recording an initial deposit
     * payment) had the exact same gap as the six methods above — only
     * TenancyPolicy::update(), no landlord check. Calling the component's
     * own save() action directly (not through Tenancies\Show at all) is
     * deliberately how this is tested — it proves the backend rejects a
     * direct Livewire invocation regardless of whatever the parent page's
     * Blade does or doesn't render.
     */
    public function test_manager_with_only_terminate_permission_cannot_record_an_initial_deposit_payment(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        Livewire::actingAs($managerUser)
            ->test(RecordDeposit::class, ['tenancy' => $tenancy])
            ->set('amount', 5000)
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'mpesa')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('deposit_payments', ['tenancy_id' => $tenancy->id]);
    }

    /** A manager with terminate permission must still be able to view the tenancy page itself — the fix hides one nested component's trigger, not the whole page. */
    public function test_manager_with_terminate_permission_can_still_view_the_tenancy_page_after_the_record_deposit_fix(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $managerUser = $this->makeManager($landlord, ['tenancies.terminate']);

        $this->actingAs($managerUser)
            ->get(route('manage.tenancies.show', $tenancy))
            ->assertOk()
            ->assertDontSeeLivewire(RecordDeposit::class);
    }
}
