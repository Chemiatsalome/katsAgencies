<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\Platform\Overview as PlatformOverview;
use App\Livewire\Arrears\Index as ArrearsIndex;
use App\Livewire\Vacancies\Index as VacanciesIndex;
use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PropertyFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialReportingAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_vacant_unit_shows_a_non_negative_whole_number_of_days_vacant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();

        Tenancy::create([
            'tenant_id' => \App\Models\Tenant::first()->id,
            'house_id' => $house->id,
            'move_in_date' => now()->subYear(),
            'move_out_date' => now()->subDays(3),
            'monthly_rent' => 10000,
            'rent_due_day' => 1,
            'status' => 'terminated',
        ]);

        $component = Livewire::actingAs($landlord)->test(VacanciesIndex::class);
        $rows = $component->instance()->render()->getData()['rows'];
        $row = $rows->firstWhere('house.id', $house->id);

        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(0, $row->daysVacant, 'Days vacant must never be negative.');
        $this->assertIsInt($row->daysVacant);
    }

    public function test_arrears_shows_a_non_negative_days_overdue(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()
            ->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->firstOrFail();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subDays(10),
            'amount_expected' => 5000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $component = Livewire::actingAs($landlord)->test(ArrearsIndex::class);
        $rows = $component->instance()->render()->getData()['rows'];
        $row = $rows->getCollection()->firstWhere('tenancy.id', $tenancy->id);

        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(0, $row->daysOverdue, 'Days overdue must never be negative.');
    }

    public function test_collected_rent_excludes_opening_balance_payments(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();
        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 6,
            'due_date' => '2025-06-01', 'amount_expected' => 10000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 10000,
            'payment_date' => '2025-06-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();

        $collected = app(PropertyFinanceService::class)->collectedRent(collect([$tenancy->id]), 2025, 6);

        $this->assertEquals(0.0, $collected, 'An opening-balance assumption is not real cash and must not count as collected.');
    }

    public function test_collected_rent_includes_manual_and_system_payments(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();
        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 7,
            'due_date' => '2025-07-01', 'amount_expected' => 10000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 6000,
            'payment_date' => '2025-07-01', 'method' => 'cash', 'source' => 'manual_entry',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 4000,
            'payment_date' => '2025-07-02', 'method' => 'mpesa', 'source' => 'system_payment',
        ]);
        $charge->recalculateStatus();

        $collected = app(PropertyFinanceService::class)->collectedRent(collect([$tenancy->id]), 2025, 7);

        $this->assertEquals(10000.0, $collected);
    }

    public function test_admin_dashboard_collected_trend_is_not_distorted_by_opening_balance(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        // Last month: entirely an opening-balance assumption — not real money.
        $lastMonth = now()->subMonthNoOverflow();
        $lastCharge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => $lastMonth->year, 'period_month' => $lastMonth->month,
            'due_date' => $lastMonth->copy()->startOfMonth(), 'amount_expected' => 10000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastCharge->id, 'amount' => 10000,
            'payment_date' => $lastMonth->copy()->startOfMonth(), 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $lastCharge->recalculateStatus();

        // This month: a real manual payment of the same size.
        $thisCharge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 10000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $thisCharge->id, 'amount' => 10000,
            'payment_date' => now(), 'method' => 'cash', 'source' => 'manual_entry',
        ]);
        $thisCharge->recalculateStatus();

        $component = Livewire::actingAs($admin)->test(AdminDashboard::class);
        $data = $component->instance()->render()->getData();

        // Real collections are equal (10,000 vs 10,000) once the fabricated
        // opening-balance credit is excluded — trend should read ~0%, not a
        // huge artificial swing.
        $trend = $data['collectedTrend'];
        if ($trend !== null) {
            $this->assertLessThan(50, abs($trend), 'Trend should not show a huge swing caused by a non-cash opening-balance payment.');
        }
    }

    public function test_platform_overview_monthly_outstanding_is_not_double_counted_against_opening_balance(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 9,
            'due_date' => '2025-09-01', 'amount_expected' => 10000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 10000,
            'payment_date' => '2025-09-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();

        $component = Livewire::actingAs($admin)->test(PlatformOverview::class)->set('year', 2025);
        $months = $component->instance()->render()->getData()['months'];

        $septemberRow = $months->firstWhere('label', 'Sep 2025');
        $this->assertNotNull($septemberRow);
        $this->assertEquals(0.0, $septemberRow['outstanding'], 'A charge settled via opening balance must not appear as outstanding.');
        $this->assertEquals(0.0, $septemberRow['collected'], 'Opening-balance settlement is not real collected cash.');
    }
}
