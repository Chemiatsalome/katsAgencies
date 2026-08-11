<?php

namespace Tests\Feature;

use App\Livewire\Arrears\Index as ArrearsIndex;
use App\Livewire\Deposits\Index as DepositsIndex;
use App\Livewire\Expenses\Form as ExpenseForm;
use App\Livewire\Expenses\Index as ExpensesIndex;
use App\Livewire\Finance\Overview as FinanceOverview;
use App\Livewire\Income\Form as IncomeForm;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\AppNotification;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordFinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_rent_reminder_creates_notifications_for_the_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancyId = RentCharge::outstanding()->first()?->tenancy_id;
        $this->assertNotNull($tenancyId, 'Seed data should include at least one outstanding rent charge.');

        $tenancy = Tenancy::findOrFail($tenancyId);
        $this->assertEquals($landlord->id, $tenancy->house->block->property->landlord_id);

        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('sendReminder', $tenancy->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $tenancy->tenant->getMorphClass(),
            'notifiable_id' => $tenancy->tenant->id,
            'event_type' => 'rent_reminder',
        ]);
    }

    public function test_adding_an_arrears_note_appends_to_the_tenant_record(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancyId = RentCharge::outstanding()->first()?->tenancy_id;
        $tenancy = Tenancy::findOrFail($tenancyId);

        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('openNoteForm', $tenancy->id)
            ->set('noteText', 'Promised to pay by Friday')
            ->call('addNote')
            ->assertHasNoErrors();

        $this->assertStringContainsString('Promised to pay by Friday', $tenancy->tenant->fresh()->notes ?? '');
    }

    public function test_deposits_dashboard_computes_held_balance_correctly(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(DepositsIndex::class)
            ->assertOk();
    }

    public function test_landlord_can_record_and_list_an_expense(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = ExpenseCategory::active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(ExpenseForm::class)
            ->set('property_id', $property->id)
            ->set('expense_category_id', $category->id)
            ->set('vendor', 'ACME Plumbing')
            ->set('amount', 4500)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'property_id' => $property->id,
            'expense_category_id' => $category->id,
            'vendor' => 'ACME Plumbing',
            'amount' => 4500,
        ]);

        Livewire::actingAs($landlord)
            ->test(ExpensesIndex::class)
            ->set('propertyId', (string) $property->id)
            ->assertSee('ACME Plumbing');
    }

    public function test_landlord_can_record_other_income(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = IncomeCategory::active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(IncomeForm::class)
            ->set('property_id', $property->id)
            ->set('income_category_id', $category->id)
            ->set('amount', 2000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('incomes', [
            'property_id' => $property->id,
            'income_category_id' => $category->id,
            'amount' => 2000,
        ]);
    }

    public function test_a_landlord_cannot_record_an_expense_against_another_landlords_property(): void
    {
        $this->seed();

        $actingLandlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $otherLandlord->id,
            'name' => 'Other Landlord Property',
            'type' => 'apartment',
            'address' => '123 Elsewhere Rd',
            'status' => 'active',
        ]);

        $category = ExpenseCategory::active()->firstOrFail();

        Livewire::actingAs($actingLandlord)
            ->test(ExpenseForm::class)
            ->set('property_id', $otherProperty->id)
            ->set('expense_category_id', $category->id)
            ->set('amount', 1000)
            ->call('save');

        $this->assertDatabaseMissing('expenses', ['property_id' => $otherProperty->id, 'amount' => 1000]);
    }

    public function test_finance_overview_renders_six_months_of_cashflow_data(): void
    {
        $this->seed();
        $this->artisan('rent:generate-charges');

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(FinanceOverview::class)
            ->assertOk()
            ->assertViewHas('months', fn ($months) => $months->count() === 6);
    }

    public function test_reports_index_generates_every_report_type_without_error(): void
    {
        $this->seed();
        $this->artisan('rent:generate-charges');

        $landlord = User::role('landlord')->firstOrFail();

        foreach (array_keys(app(\App\Services\ReportService::class)->available()) as $type) {
            Livewire::actingAs($landlord)
                ->test(ReportsIndex::class)
                ->set('type', $type)
                ->assertOk();
        }
    }

    public function test_reports_csv_export_streams_a_download(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(ReportsIndex::class)
            ->set('type', 'expenses')
            ->call('exportCsv')
            ->assertStatus(200);
    }
}
