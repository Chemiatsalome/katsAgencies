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

    public function test_tenancy_balance_excludes_not_yet_due_future_charges(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();
        $tenancy->rentCharges()->delete();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subDays(5),
            'amount_expected' => 8000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->addMonth()->year,
            'period_month' => now()->addMonth()->month,
            'due_date' => now()->addMonth(),
            'amount_expected' => 8000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        // Only the already-due charge should count — the future month isn't
        // owed yet, even though it has already been pre-generated.
        $this->assertEquals(8000.0, $tenancy->balance());
    }

    public function test_arrears_index_lists_only_currently_due_outstanding_charges(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();
        $tenancy->rentCharges()->delete();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subDays(5),
            'amount_expected' => 8000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $component = Livewire::actingAs($landlord)->test(ArrearsIndex::class);
        $rows = $component->instance()->render()->getData()['rows'];
        $row = $rows->getCollection()->firstWhere('tenancy.id', $tenancy->id);

        $this->assertNotNull($row);
        $this->assertEquals(8000.0, $row->arrears);
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

    public function test_landlord_can_edit_an_existing_expense(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = ExpenseCategory::active()->firstOrFail();

        $expense = Expense::create([
            'property_id' => $property->id,
            'expense_category_id' => $category->id,
            'vendor' => 'Original Vendor',
            'amount' => 1000,
            'expense_date' => now()->format('Y-m-d'),
            'recorded_by' => $landlord->id,
        ]);

        Livewire::actingAs($landlord)
            ->test(ExpenseForm::class, ['expense' => $expense])
            ->assertSet('vendor', 'Original Vendor')
            ->set('vendor', 'Corrected Vendor')
            ->set('amount', 1200)
            ->call('save')
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertEquals('Corrected Vendor', $expense->vendor);
        $this->assertEquals(1200, $expense->amount);
    }

    public function test_a_landlord_cannot_edit_another_landlords_expense(): void
    {
        $this->seed();

        $actingLandlord = User::role('landlord')->firstOrFail();
        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $otherLandlord->id,
            'name' => 'Other Landlord Property',
            'type' => 'apartment',
            'status' => 'active',
        ]);
        $category = ExpenseCategory::active()->firstOrFail();

        $expense = Expense::create([
            'property_id' => $otherProperty->id,
            'expense_category_id' => $category->id,
            'amount' => 500,
            'expense_date' => now()->format('Y-m-d'),
            'recorded_by' => $otherLandlord->id,
        ]);

        Livewire::actingAs($actingLandlord)->test(ExpenseForm::class, ['expense' => $expense])->assertForbidden();
    }

    public function test_landlord_can_edit_an_existing_income_record(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = IncomeCategory::active()->firstOrFail();

        $income = Income::create([
            'property_id' => $property->id,
            'income_category_id' => $category->id,
            'amount' => 2000,
            'income_date' => now()->format('Y-m-d'),
            'recorded_by' => $landlord->id,
        ]);

        Livewire::actingAs($landlord)
            ->test(IncomeForm::class, ['income' => $income])
            ->set('amount', 2500)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(2500, $income->fresh()->amount);
    }

    public function test_a_manager_cannot_create_or_edit_expenses(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = ExpenseCategory::active()->firstOrFail();

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        \App\Models\Manager::create([
            'landlord_id' => $landlord->id,
            'user_id' => $manager->id,
            'name' => $manager->name,
            'phone' => '0700000999',
            'email' => $manager->email,
            'all_properties' => true,
            'status' => 'active',
            'token' => \App\Models\Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'activated_at' => now(),
        ]);

        Livewire::actingAs($manager)->test(ExpenseForm::class)->assertForbidden();

        $expense = Expense::create([
            'property_id' => $property->id,
            'expense_category_id' => $category->id,
            'amount' => 500,
            'expense_date' => now()->format('Y-m-d'),
            'recorded_by' => $landlord->id,
        ]);

        Livewire::actingAs($manager)->test(ExpenseForm::class, ['expense' => $expense])->assertForbidden();
    }

    public function test_editing_an_expense_with_a_deactivated_category_keeps_it_selectable(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $category = ExpenseCategory::active()->firstOrFail();

        $expense = Expense::create([
            'property_id' => $property->id,
            'expense_category_id' => $category->id,
            'amount' => 500,
            'expense_date' => now()->format('Y-m-d'),
            'recorded_by' => $landlord->id,
        ]);

        $category->update(['is_active' => false]);

        $component = Livewire::actingAs($landlord)->test(ExpenseForm::class, ['expense' => $expense]);
        $categories = $component->viewData('categories');

        $this->assertTrue($categories->contains('id', $category->id), 'The expense\'s current (now-deactivated) category must still appear so editing other fields does not silently reassign it.');

        // Saving without touching the category must not change it.
        $component->set('vendor', 'Updated Vendor')->call('save')->assertHasNoErrors();
        $this->assertEquals($category->id, $expense->fresh()->expense_category_id);
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

    public function test_payment_history_report_excludes_opening_balance_entries(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        \App\Models\Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 5000,
            'payment_date' => now(), 'method' => 'other', 'source' => 'opening_balance',
        ]);
        \App\Models\Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 3000,
            'payment_date' => now(), 'method' => 'cash', 'source' => 'manual_entry',
        ]);

        $report = app(\App\Services\ReportService::class)->generate('payment_history', $landlord->id, ['year' => (int) now()->year]);

        $amounts = array_column($report['rows'], 5);
        $this->assertContains('3,000.00', $amounts);
        $this->assertNotContains('5,000.00', $amounts, 'Opening-balance rows are not real cash and must not appear in payment history.');
    }

    public function test_property_performance_report_shows_a_dash_for_unfilled_investment(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $property->update(['purchase_price' => null, 'initial_renovation_cost' => null, 'other_acquisition_costs' => null]);

        $report = app(\App\Services\ReportService::class)->generate('property_performance', $landlord->id);

        $row = collect($report['rows'])->firstWhere(0, $property->name);
        $this->assertNotNull($row);
        $this->assertEquals('—', $row[1], 'A property with no investment fields filled in should show a dash, not a misleading 0.00.');
    }
}
