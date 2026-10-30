<?php

namespace Tests\Feature;

use App\Livewire\Applications\Form as ApplicationForm;
use App\Livewire\Applications\Show as ApplicationShow;
use App\Livewire\Tenancies\Form as TenancyForm;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Models\Application;
use App\Models\House;
use App\Models\Payment;
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
}
