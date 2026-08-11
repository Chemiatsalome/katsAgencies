<?php

namespace Tests\Feature;

use App\Livewire\Applications\Form as ApplicationForm;
use App\Livewire\Applications\Show as ApplicationShow;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Models\Application;
use App\Models\DepositRefund;
use App\Models\House;
use App\Models\Tenancy;
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
            ->call('moveOut')
            ->assertHasErrors('terminationReason');

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('moveOutStatus', 'terminated')
            ->set('terminationReason', 'Non-payment of rent')
            ->call('moveOut')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertEquals('terminated', $tenancy->status);
        $this->assertEquals('Non-payment of rent', $tenancy->termination_reason);
        $this->assertEquals('vacant', $tenancy->house->fresh()->status);
    }

    public function test_deposit_refund_is_recorded_against_the_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();
        $tenancy->update(['status' => 'completed']);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('refund_amount', 10000)
            ->set('refund_deductions', 2000)
            ->set('refund_reason', 'Cleaning costs')
            ->call('recordRefund')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deposit_refunds', [
            'tenancy_id' => $tenancy->id,
            'amount' => 10000,
            'deductions' => 2000,
        ]);
        $this->assertEquals(12000, $tenancy->depositRefundedTotal() + $tenancy->depositDeductedTotal());
    }
}
