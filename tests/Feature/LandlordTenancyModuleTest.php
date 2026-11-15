<?php

namespace Tests\Feature;

use App\Livewire\Applications\Form as ApplicationForm;
use App\Livewire\Applications\Show as ApplicationShow;
use App\Livewire\Deposits\RecordDeposit;
use App\Livewire\Tenancies\Form as TenancyForm;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenants\Form as TenantsForm;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Models\AppNotification;
use App\Models\Application;
use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordTenancyModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_show_tabs_render(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenant = \App\Models\Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        foreach (['overview', 'payments', 'arrears', 'complaints', 'documents', 'activity'] as $tab) {
            $this->actingAs($landlord)
                ->get(route('manage.tenants.show', $tenant).'?tab='.$tab)
                ->assertOk();
        }
    }

    // --- Phase 9: landlord-side tenant phone validation ---------------------------------

    public function test_an_invalid_phone_number_is_rejected_on_the_landlord_tenant_form(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenant = Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();
        $originalPhone = $tenant->phone;

        Livewire::actingAs($landlord)
            ->test(TenantsForm::class, ['tenant' => $tenant])
            ->set('phone', 'not-a-phone-number')
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertEquals($originalPhone, $tenant->fresh()->phone);
    }

    /** A genuinely valid Kenyan mobile number, in the common 07xx local format, must still be accepted. */
    public function test_a_valid_kenyan_phone_number_is_accepted_on_the_landlord_tenant_form(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenant = Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenantsForm::class, ['tenant' => $tenant])
            ->set('phone', '0722334455')
            ->call('save')
            ->assertHasNoErrors('phone');

        $this->assertEquals('0722334455', $tenant->fresh()->phone);
    }

    /** Optional business name (part D of the property-category work) on the standalone Tenants\Form, never required. */
    public function test_a_new_standalone_tenants_optional_business_name_is_saved_when_provided(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenantsForm::class)
            ->set('name', 'Office Tenant')
            ->set('business_name', 'Acme Consulting Ltd')
            ->set('phone', '0722998877')
            ->set('payment_due_day', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', ['name' => 'Office Tenant', 'business_name' => 'Acme Consulting Ltd']);
    }

    /**
     * A landlord with direct M-Pesa collection configured settles rent
     * straight into their own paybill/till — Kats never holds that money,
     * so it's never part of what gets disbursed to them. The Payment
     * History table showed every M-Pesa payment identically regardless of
     * collection_mode, so there was no way to tell, from this page alone,
     * which payments already reached the landlord directly versus which
     * ones are sitting in Kats' paybill awaiting disbursement.
     */
    public function test_payment_history_distinguishes_direct_collection_from_platform(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 16,
            'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa',
            'collection_mode' => 'direct',
            'reference_number' => 'DIRECT001',
            'status' => 'confirmed',
            'recorded_by' => $landlord->id,
        ]);

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 50,
            'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa',
            'collection_mode' => 'platform',
            'reference_number' => 'PLATFORM001',
            'status' => 'confirmed',
            'recorded_by' => $landlord->id,
        ]);

        $component = Livewire::actingAs($landlord)->test(TenancyShow::class, ['tenancy' => $tenancy]);

        $component->assertSeeHtml('DIRECT001');
        $component->assertSeeHtml('PLATFORM001');

        $rendered = $component->html();
        $rows = preg_split('/(?=<tr>)/', $rendered);

        $directRow = collect($rows)->first(fn ($row) => str_contains($row, 'DIRECT001'));
        $platformRow = collect($rows)->first(fn ($row) => str_contains($row, 'PLATFORM001'));

        $this->assertNotNull($directRow);
        $this->assertNotNull($platformRow);
        $this->assertStringContainsString('>Direct<', $directRow, 'The direct-collection payment\'s row should carry a "Direct" badge.');
        $this->assertStringNotContainsString('>Direct<', $platformRow, 'The platform-collection payment\'s row should not carry a "Direct" badge.');

        // Only one "Direct" badge in the whole table.
        $this->assertSame(1, substr_count($rendered, '>Direct<'));
    }

    public function test_application_can_be_approved_and_converted_to_a_real_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $vacantHouse = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->first();
        $this->assertNotNull($vacantHouse, 'Seed data should include at least one vacant unit for this property.');

        Livewire::actingAs($landlord)
            ->test(ApplicationForm::class)
            ->set('desired_property_id', $property->id)
            ->set('desired_house_id', $vacantHouse->id)
            ->set('applicant_name', 'Test Applicant')
            ->set('applicant_phone', '0700000111')
            ->call('save')
            ->assertHasNoErrors();

        $application = Application::where('applicant_phone', '0700000111')->firstOrFail();
        $this->assertEquals('submitted', $application->status);

        Livewire::actingAs($landlord)
            ->test(ApplicationShow::class, ['application' => $application])
            ->set('house_id', $vacantHouse->id)
            ->set('monthly_rent', $vacantHouse->monthly_rent)
            ->set('deposit_required', $vacantHouse->deposit_amount)
            ->call('approveAndConvert')
            ->assertHasNoErrors();

        $application->refresh();
        $this->assertEquals('approved', $application->status);
        $this->assertNotNull($application->converted_tenancy_id);

        $tenancy = Tenancy::find($application->converted_tenancy_id);
        $this->assertEquals('active', $tenancy->status);
        $this->assertEquals($vacantHouse->id, $tenancy->house_id);
        $this->assertEquals('occupied', $vacantHouse->fresh()->status);
    }

    // --- Phase 10: application phone validation and format-tolerant tenant matching ----

    public function test_an_invalid_applicant_phone_is_rejected_on_the_application_form(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $vacantHouse = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->first();

        Livewire::actingAs($landlord)
            ->test(ApplicationForm::class)
            ->set('desired_property_id', $property->id)
            ->set('desired_house_id', $vacantHouse->id)
            ->set('applicant_name', 'Bad Phone Applicant')
            ->set('applicant_phone', 'not-a-phone-number')
            ->call('save')
            ->assertHasErrors('applicant_phone');

        $this->assertDatabaseMissing('applications', ['applicant_name' => 'Bad Phone Applicant']);
    }

    /**
     * The approved applicant's phone is stored in a different raw format
     * from an existing Tenant's own phone — approval must match the
     * existing tenant rather than create a duplicate.
     */
    public function test_approving_an_application_matches_an_existing_tenant_with_a_differently_formatted_phone(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $vacantHouse = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->first();

        $existingTenant = Tenant::create(['name' => 'Returning Tenant', 'phone' => '+254744556677', 'payment_due_day' => 1]);

        $application = Application::create([
            'desired_property_id' => $property->id,
            'desired_house_id' => $vacantHouse->id,
            'applicant_name' => 'Returning Tenant',
            'applicant_phone' => '0744556677',
            'application_date' => now()->format('Y-m-d'),
            'status' => 'submitted',
        ]);

        Livewire::actingAs($landlord)
            ->test(ApplicationShow::class, ['application' => $application])
            ->set('house_id', $vacantHouse->id)
            ->set('monthly_rent', $vacantHouse->monthly_rent)
            ->set('deposit_required', $vacantHouse->deposit_amount)
            ->call('approveAndConvert')
            ->assertHasNoErrors();

        $this->assertEquals(1, Tenant::where('name', 'Returning Tenant')->count(), 'Must reuse the existing tenant, not create a duplicate.');

        $tenancy = Tenancy::find($application->fresh()->converted_tenancy_id);
        $this->assertEquals($existingTenant->id, $tenancy->tenant_id);
    }

    public function test_ending_a_tenancy_as_terminated_requires_a_reason_and_records_it(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'terminated')
            ->call('reviewTermination')
            ->assertHasErrors('terminationReason');

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'terminated')
            ->set('terminationReason', 'Non-payment of rent')
            // The current period's rent charge is now ensured to exist as
            // soon as this page is viewed (see Tenancies\Show::mount()), so
            // this seeded tenancy genuinely owes the current month's rent —
            // acknowledging it is the realistic path, not a quirk of seeding.
            ->set('acknowledgeUnpaidBalance', true)
            ->call('reviewTermination')
            ->assertHasNoErrors()
            ->call('confirmTermination')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertEquals('terminated', $tenancy->status);
        $this->assertEquals('Non-payment of rent', $tenancy->termination_reason);
        $this->assertEquals('vacant', $tenancy->house->fresh()->status);
    }

    public function test_issuing_notice_computes_a_proposed_vacate_date_from_notice_period_days(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['notice_period_days' => 30]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('toggleGiveNoticeForm')
            ->set('notice_reason_category', 'other')
            ->set('notice_date', now()->format('Y-m-d'))
            ->call('issueNotice')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertEquals('notice_given', $tenancy->status);
        $this->assertEquals(now()->format('Y-m-d'), $tenancy->notice_date->format('Y-m-d'));

        $notice = $tenancy->activeNotice();
        $this->assertNotNull($notice);
        $this->assertEquals(now()->addDays(30)->format('Y-m-d'), $notice->proposed_vacate_date->format('Y-m-d'));
    }

    public function test_ending_a_tenancy_as_completed_records_move_out_date_and_settles_deposit_in_one_call(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 5000, 'deposit_paid' => 5000])->save();
        $backdatedMoveOut = now()->subDays(3)->format('Y-m-d');

        // Deductions are recorded as their own itemised action now (see
        // DepositService::recordDeduction), independent of the move-out
        // wizard — only the refund amount is still settled from this form.
        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'completed')
            ->set('actualMoveOutDate', $backdatedMoveOut)
            ->set('endTenancyNotes', 'Left in good condition')
            ->set('finalRefundAmount', '5000')
            // Same as above — this tenancy's current-period rent charge now
            // exists as soon as the page is viewed, so it genuinely owes it.
            ->set('acknowledgeUnpaidBalance', true)
            ->call('reviewTermination')
            ->assertHasNoErrors()
            ->call('confirmTermination')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertEquals('completed', $tenancy->status);
        $this->assertEquals($backdatedMoveOut, $tenancy->move_out_date->format('Y-m-d'));
        $this->assertEquals('Left in good condition', $tenancy->notes);
        $this->assertEquals('vacant', $tenancy->house->fresh()->status);

        $this->assertDatabaseHas('deposit_refunds', [
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
        ]);
    }

    public function test_deposit_refund_is_recorded_against_the_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 12000, 'deposit_paid' => 12000])->save();
        $tenancy->update(['status' => 'completed']);

        app(\App\Services\DepositService::class)->recordDeduction($tenancy, 2000, 'Cleaning costs', now()->format('Y-m-d'), $landlord->id);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('refund_amount', 10000)
            ->set('refund_date', now()->format('Y-m-d'))
            ->set('refund_notes', 'Final settlement')
            ->call('recordRefund')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_refunds', [
            'tenancy_id' => $tenancy->id,
            'amount' => 10000,
        ]);
        $this->assertEquals(12000, $tenancy->depositRefundedTotal() + $tenancy->depositDeductedTotal());
    }

    /**
     * Landlord-side regression coverage for the stale-bedrooms sibling fix
     * (Manager/Agent/Deposit follow-up audit): Tenancies\Show::recordRefund/
     * recordDeduction/voidDeduction/resolveDispute/confirmDepositPayment/
     * disputeDepositPayment now additionally require hasRole('landlord')
     * via authorizeDepositFinance() — these pin that a landlord's own
     * access to every one of those six actions is completely unaffected.
     */
    public function test_landlord_can_still_record_a_full_deposit_refund(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 8000, 'deposit_paid' => 8000])->save();

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('refund_amount', 8000)
            ->set('refund_date', now()->format('Y-m-d'))
            ->call('recordRefund')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_refunds', ['tenancy_id' => $tenancy->id, 'amount' => 8000]);
        $this->assertEquals(0, $tenancy->depositHeldBalance());
    }

    public function test_landlord_can_still_record_a_deposit_deduction_via_the_tenancy_show_form(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 9000, 'deposit_paid' => 9000])->save();

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('deduction_amount', 1500)
            ->set('deduction_reason', 'Broken window')
            ->set('deduction_date', now()->format('Y-m-d'))
            ->call('recordDeduction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_deductions', [
            'tenancy_id' => $tenancy->id,
            'amount' => 1500,
            'reason' => 'Broken window',
        ]);
    }

    public function test_landlord_can_still_void_a_deposit_deduction(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 9000, 'deposit_paid' => 9000])->save();

        $deduction = app(\App\Services\DepositService::class)->recordDeduction($tenancy, 1500, 'Recorded in error', now()->format('Y-m-d'), $landlord->id);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('voidingDeductionId', $deduction->id)
            ->set('void_reason', 'Wrong tenant')
            ->call('voidDeduction')
            ->assertHasNoErrors();

        $this->assertNotNull($deduction->fresh()->voided_at);
    }

    public function test_landlord_can_still_resolve_a_disputed_deposit_deduction(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['deposit_required' => 9000, 'deposit_paid' => 9000])->save();

        $deduction = app(\App\Services\DepositService::class)->recordDeduction($tenancy, 1500, 'Cleaning', now()->format('Y-m-d'), $landlord->id);
        $deduction->forceFill([
            'disputed_at' => now(),
            'dispute_reason' => 'Not fair',
            'disputed_by' => $tenancy->tenant->user_id ?? $landlord->id,
        ])->save();

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('resolveDispute', $deduction->id, 'uphold')
            ->assertHasNoErrors();

        $this->assertNotNull($deduction->fresh()->dispute_resolved_at);
    }

    public function test_landlord_can_still_confirm_and_dispute_deposit_payments(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        $toConfirm = \App\Models\DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 4000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa', 'status' => 'awaiting_landlord_confirmation',
        ]);
        $toDispute = \App\Models\DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 3000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa', 'status' => 'awaiting_landlord_confirmation',
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmDepositPayment', $toConfirm->id)
            ->assertHasNoErrors()
            ->call('disputeDepositPayment', $toDispute->id, 'Amount does not match')
            ->assertHasNoErrors();

        $this->assertEquals('confirmed', $toConfirm->fresh()->status);
        $this->assertEquals('disputed', $toDispute->fresh()->status);
    }

    /** Follow-up regression test: a landlord's own access to Deposits\RecordDeposit (recording an initial deposit payment) must be completely unaffected by its new landlord-only guard. */
    public function test_landlord_can_still_record_an_initial_deposit_payment(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(RecordDeposit::class, ['tenancy' => $tenancy])
            ->set('amount', 6000)
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'mpesa')
            ->set('reference_number', 'ABC123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_payments', [
            'tenancy_id' => $tenancy->id,
            'amount' => 6000,
            'reference_number' => 'ABC123',
        ]);
    }

    public function test_moving_out_an_already_ended_tenancy_is_a_no_op(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['status' => 'completed', 'move_out_date' => now()->subDay()]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'terminated')
            ->set('terminationReason', 'Duplicate submit')
            ->call('reviewTermination');

        // Status/termination_reason must not have been overwritten by the
        // second submission — the guard should have short-circuited first,
        // before the confirmation summary was even shown.
        $this->assertNull($tenancy->fresh()->termination_reason);
        $this->assertEquals('completed', $tenancy->fresh()->status);
    }

    public function test_issuing_notice_on_an_already_ended_tenancy_is_a_no_op(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['status' => 'terminated', 'move_out_date' => now()->subDay()]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('notice_reason_category', 'other')
            ->set('notice_date', now()->format('Y-m-d'))
            ->call('issueNotice');

        $this->assertEquals('terminated', $tenancy->fresh()->status);
        $this->assertNull($tenancy->fresh()->notice_date);
        $this->assertNull($tenancy->fresh()->activeNotice());
    }

    public function test_creating_a_tenancy_on_a_house_that_just_became_unavailable_is_rejected(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();
        $tenant = Tenant::create(['name' => 'Race Condition Tenant', 'phone' => '0700000444']);

        $component = Livewire::actingAs($landlord)
            ->test(TenancyForm::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('tenant_id', $tenant->id)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 1);

        // Simulate another request winning the race right before this one submits.
        $house->update(['status' => 'occupied']);

        $component->call('save')->assertHasErrors('house_id');

        $this->assertDatabaseMissing('tenancies', ['house_id' => $house->id, 'tenant_id' => $tenant->id]);
    }

    // --- Phase 10: inline new-tenant phone validation on the tenancy form --------------

    public function test_an_invalid_phone_is_rejected_when_creating_a_tenancy_with_a_new_inline_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenancyForm::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('newTenant', true)
            ->set('new_tenant_name', 'Bad Phone Tenant')
            ->set('new_tenant_phone', 'not-a-phone-number')
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 1)
            ->call('save')
            ->assertHasErrors('new_tenant_phone');

        $this->assertDatabaseMissing('tenants', ['name' => 'Bad Phone Tenant']);
    }

    /** A genuinely valid Kenyan mobile number must still be accepted. */
    public function test_a_valid_phone_is_accepted_when_creating_a_tenancy_with_a_new_inline_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenancyForm::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('newTenant', true)
            ->set('new_tenant_name', 'Good Phone Tenant')
            ->set('new_tenant_phone', '0733445566')
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', ['name' => 'Good Phone Tenant', 'phone' => '0733445566']);
    }

    /** Optional business name (part D of the property-category work) — never required, and blank stays null rather than an empty string. */
    public function test_a_new_inline_tenants_optional_business_name_is_saved_when_provided(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(TenancyForm::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('newTenant', true)
            ->set('new_tenant_name', 'Shop Owner Tenant')
            ->set('new_tenant_business_name', "Mama Chips' Kiosk")
            ->set('new_tenant_phone', '0733445577')
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', ['name' => 'Shop Owner Tenant', 'business_name' => "Mama Chips' Kiosk"]);
    }

    /**
     * Real-world bug: picking a month preset (e.g. "3 months") computed
     * notice_period_days via diffInDays() without forcing it absolute —
     * Carbon's default there is signed, not always-positive, so the later
     * date diffed against the earlier move-in date came out negative
     * (e.g. -92), which then failed the field's own 'min:0' validation
     * rule on save.
     */
    /**
     * A tenant added directly via the "Move In Tenant" form has a Tenant
     * row but no linked User — they have no way to log in to the portal
     * until a landlord sends them an invite. This was only reachable from
     * the Tenancies list page, not from the tenancy's own Show page.
     */
    public function test_landlord_can_send_a_portal_invite_from_the_tenancy_show_page(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();
        $tenancy->tenant->update(['user_id' => null, 'phone' => '0712345678', 'portal_invite_token' => null]);

        $component = Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->assertSee('Send Invite')
            ->call('sendPortalInvite')
            ->assertHasNoErrors();

        $tenancy->tenant->refresh();
        $this->assertNotNull($tenancy->tenant->portal_invite_token);
        $this->assertNotNull($tenancy->tenant->portal_invite_sent_at);
        $this->assertNotNull($component->get('generatedInviteLink'));
        $this->assertStringContainsString($tenancy->tenant->portal_invite_token, $component->get('generatedInviteLink'));
        $this->assertNotNull($component->get('generatedInviteWhatsappUrl'));
    }

    /**
     * Same manual "Send Reminder" as the Rent & Payments page, now also
     * reachable from a tenancy's own Show page — targets the oldest
     * outstanding charge on the tenancy, since a tenancy can have several.
     */
    public function test_landlord_can_send_a_reminder_from_the_tenancy_show_page(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        RentCharge::where('tenancy_id', $tenancy->id)->delete();
        $older = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);
        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->assertSee('Send Reminder')
            ->call('sendReminder')
            ->assertHasNoErrors();

        // Targets the oldest outstanding charge, not just any/the newest.
        $this->assertTrue(
            AppNotification::where('related_type', $older->getMorphClass())
                ->where('related_id', $older->id)
                ->where('event_type', 'rent_reminder_manual_'.now()->format('Ymd'))
                ->exists()
        );
    }

    public function test_send_reminder_button_is_hidden_when_the_tenant_has_no_balance(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        // mount() first — it's what calls ensureChargesForPeriod() and may
        // create this period's charge if it didn't already exist, so it
        // must run before marking everything paid below, not after.
        $component = Livewire::actingAs($landlord)->test(TenancyShow::class, ['tenancy' => $tenancy]);

        RentCharge::where('tenancy_id', $tenancy->id)->update(['amount_paid' => \Illuminate\Support\Facades\DB::raw('amount_expected'), 'status' => 'paid']);

        $component->call('refreshTenancy')->assertDontSee('Send Reminder');
    }

    public function test_picking_a_notice_period_month_preset_computes_a_positive_day_count(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();
        $tenant = Tenant::create(['name' => 'Notice Preset Tenant', 'phone' => '0700000555']);

        $component = Livewire::actingAs($landlord)
            ->test(TenancyForm::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('tenant_id', $tenant->id)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 1)
            ->set('notice_period_months', '3');

        $this->assertGreaterThan(0, (int) $component->get('notice_period_days'));

        $component->call('save')->assertHasNoErrors('notice_period_days');

        $this->assertDatabaseHas('tenancies', [
            'house_id' => $house->id,
            'tenant_id' => $tenant->id,
        ]);
        $this->assertGreaterThan(0, Tenancy::where('house_id', $house->id)->where('tenant_id', $tenant->id)->firstOrFail()->notice_period_days);
    }

    public function test_a_second_application_cannot_be_converted_onto_an_already_taken_house(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $application = Application::create([
            'desired_property_id' => $property->id,
            'desired_house_id' => $house->id,
            'applicant_name' => 'Second Applicant',
            'applicant_phone' => '0700000222',
            'application_date' => now()->format('Y-m-d'),
            'status' => 'submitted',
        ]);

        // Someone else takes the unit first.
        $house->update(['status' => 'occupied']);

        Livewire::actingAs($landlord)
            ->test(ApplicationShow::class, ['application' => $application])
            ->set('house_id', $house->id)
            ->set('monthly_rent', $house->monthly_rent)
            ->set('deposit_required', $house->deposit_amount)
            ->call('approveAndConvert')
            ->assertHasErrors('house_id');

        $this->assertEquals('submitted', $application->fresh()->status);
        $this->assertNull($application->fresh()->converted_tenancy_id);
    }

    public function test_application_status_cannot_be_forced_to_approved_directly(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $application = Application::create([
            'desired_property_id' => $property->id,
            'desired_house_id' => $house->id,
            'applicant_name' => 'Third Applicant',
            'applicant_phone' => '0700000333',
            'application_date' => now()->format('Y-m-d'),
            'status' => 'submitted',
        ]);

        Livewire::actingAs($landlord)
            ->test(ApplicationShow::class, ['application' => $application])
            ->call('updateStatus', 'approved')
            ->assertStatus(422);

        $this->assertEquals('submitted', $application->fresh()->status);
    }

    public function test_a_tenant_with_an_active_tenancy_cannot_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenant = $tenancy->tenant;

        Livewire::actingAs($landlord)
            ->test(TenantsIndex::class)
            ->call('delete', $tenant->id);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    // --- F2: a moved-out tenant's financial/contractual history is protected too -------

    public function test_a_moved_out_tenant_with_historical_rent_payment_deposit_and_contract_records_cannot_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->forceFill(['status' => 'terminated'])->save();
        $tenant = $tenancy->tenant;

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 5000, 'status' => 'paid',
        ]);
        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 5000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'F2-HISTORY-PAYMENT', 'recorded_by' => $landlord->id, 'status' => 'confirmed',
        ]);
        $depositPayment = \App\Models\DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 6000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'cash', 'status' => 'confirmed', 'confirmed_by' => $landlord->id, 'confirmed_at' => now(),
        ]);
        $contract = \App\Models\Contract::create([
            'tenancy_id' => $tenancy->id, 'source' => 'system_template', 'status' => 'signed',
            'body_snapshot' => 'Agreement text', 'version' => 1, 'sent_at' => now(), 'signed_at' => now(),
        ]);

        Livewire::actingAs($landlord)
            ->test(TenantsIndex::class)
            ->call('delete', $tenant->id);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseHas('tenancies', ['id' => $tenancy->id]);
        $this->assertDatabaseHas('rent_charges', ['id' => $charge->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('deposit_payments', ['id' => $depositPayment->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
    }

    /**
     * F2 only adds a tenancies()->exists() guard inside Tenants\Index's own
     * delete() action — it introduces no new restriction on the Tenant
     * model itself, so a tenant with zero tenancies remains exactly as
     * deletable at the model layer as before this fix.
     *
     * Note, traced but deliberately left alone as out of F2's scope: that
     * same zero-tenancy tenant is already unreachable through the actual
     * landlord-facing Tenants\Index UI today, for a reason that predates
     * this fix and has nothing to do with history protection —
     * TenantPolicy::delete() requires Tenant::accessibleBy($user), which
     * itself requires whereHas('tenancies', ...), so it never matches a
     * tenant with no tenancy at all. Changing that accessibility scope is
     * not a retention/data-integrity concern and is left untouched here.
     */
    public function test_a_tenant_who_has_never_had_a_tenancy_remains_deletable_at_the_model_level(): void
    {
        $this->seed();

        $tenant = Tenant::create(['name' => 'Never Housed Tenant', 'phone' => '0700555666', 'payment_due_day' => 1]);
        $this->assertTrue($tenant->tenancies()->doesntExist());

        $tenant->delete();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_deleting_a_tenants_portal_login_preserves_their_business_record_and_history(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $portalUser = User::factory()->create();
        $portalUser->assignRole('tenant');
        $tenant->update(['user_id' => $portalUser->id]);

        $portalUser->delete();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'user_id' => null]);
        $this->assertDatabaseHas('tenancies', ['id' => $tenancy->id]);
    }
}
