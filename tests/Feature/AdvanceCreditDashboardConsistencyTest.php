<?php

namespace Tests\Feature;

use App\Livewire\Admin\Platform\Overview as AdminPlatformOverview;
use App\Livewire\Landlord\Dashboard as LandlordDashboard;
use App\Livewire\RentCharges\Index as RentChargesIndex;
use App\Models\Block;
use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\PropertyFinanceService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tier 1b (2026-10-25): Tier 1 fixed Tenancy::balance(), PropertyFinanceService
 * ::outstanding(), ReportService::arrears(), and the Tenancies\Show/Tenant\Rent
 * per-charge tables. This phase closes the remaining reporting surfaces —
 * Landlord\Dashboard's overdueAmount/dueTodayAmount, RentCharges\Index, and
 * Admin\Platform\Overview's totalArrears/per-landlord outstanding — and
 * confirms monthlyTotals()'s period-specific figure was deliberately left
 * un-netted (a different metric, not an oversight).
 */
class AdvanceCreditDashboardConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord, float $monthlyRent = 20000): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $block = Block::create(['property_id' => $property->id, 'name' => 'T1b Block '.str()->random(6)]);
        $house = House::create([
            'block_id' => $block->id,
            'house_number' => 'T1B-'.str()->random(6),
            'monthly_rent' => $monthlyRent,
            'deposit_amount' => $monthlyRent,
            'status' => 'vacant',
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant(
            Tenant::create(['name' => 'T1b Tenant '.str()->random(6), 'phone' => '07'.random_int(10000000, 99999999)]),
            [
                'house_id' => $house->id,
                'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
                'monthly_rent' => $monthlyRent,
                'deposit_required' => $house->deposit_amount,
                'rent_due_day' => 1,
            ]
        );
    }

    private function overpayToCreateAdvance(Tenancy $tenancy, User $landlord, float $expected, float $paid, \DateTimeInterface|string $dueDate): RentCharge
    {
        $date = \Illuminate\Support\Carbon::parse($dueDate);
        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => $date->year,
            'period_month' => $date->month,
            'due_date' => $date,
            'amount_expected' => $expected,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => $paid, 'payment_date' => $date, 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $charge->recalculateStatus();

        return $charge;
    }

    public function test_landlord_dashboard_overdue_amount_is_netted_against_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $before = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('overdueAmount');

        $tenancy = $this->createTenancyFor($landlord);
        $this->overpayToCreateAdvance($tenancy, $landlord, 20000, 28000, now()->subMonth());
        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $after = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('overdueAmount');

        $this->assertEqualsWithDelta(12000.0, $after - $before, 0.01, '20,000 overdue minus the 8,000 surplus from last month.');
    }

    public function test_landlord_dashboard_due_today_amount_is_netted_against_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $before = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('dueTodayAmount');

        $tenancy = $this->createTenancyFor($landlord);
        $this->overpayToCreateAdvance($tenancy, $landlord, 20000, 25000, now()->subMonth());
        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->startOfDay(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $after = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('dueTodayAmount');

        $this->assertEqualsWithDelta(15000.0, $after - $before, 0.01, '20,000 due today minus the 5,000 surplus from last month.');
    }

    public function test_landlord_dashboard_overdue_amount_never_lets_one_tenancy_cover_another_under_the_same_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $before = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('overdueAmount');

        $tenancyWithCredit = $this->createTenancyFor($landlord);
        // 20,000 surplus — exactly enough to fully cover this tenancy's own 20,000 charge.
        $this->overpayToCreateAdvance($tenancyWithCredit, $landlord, 20000, 40000, now()->subMonth());
        RentCharge::create([
            'tenancy_id' => $tenancyWithCredit->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $tenancyWithoutCredit = $this->createTenancyFor($landlord, 15000);
        RentCharge::create([
            'tenancy_id' => $tenancyWithoutCredit->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 15000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $after = Livewire::actingAs($landlord)->test(LandlordDashboard::class)->viewData('overdueAmount');

        // Tenancy A's charge is fully covered by its own 20,000 surplus (net
        // 0) — tenancy B has no credit of its own, so its full 15,000 must
        // still show, even though tenancy A's credit alone would "cover" it.
        $this->assertEqualsWithDelta(15000.0, $after - $before, 0.01);
    }

    public function test_admin_platform_overview_isolates_separate_landlords_credit_balances(): void
    {
        $this->seed();
        $landlordA = User::role('landlord')->firstOrFail();

        $landlordB = User::factory()->create(['status' => 'active']);
        $landlordB->assignRole('landlord');
        \App\Models\Property::create([
            'landlord_id' => $landlordB->id, 'name' => 'T1b Landlord B Property',
            'type' => 'apartment', 'address' => '1 Isolation Rd', 'status' => 'active',
        ]);

        $beforeTotal = Livewire::actingAs($landlordA)->test(AdminPlatformOverview::class)->viewData('totalArrears');

        $tenancyA = $this->createTenancyFor($landlordA);
        // 20,000 surplus — exactly enough to fully cover landlord A's own 20,000 charge.
        $this->overpayToCreateAdvance($tenancyA, $landlordA, 20000, 40000, now()->subMonth());
        RentCharge::create([
            'tenancy_id' => $tenancyA->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $tenancyB = $this->createTenancyFor($landlordB, 18000);
        RentCharge::create([
            'tenancy_id' => $tenancyB->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 18000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $afterTotal = Livewire::actingAs($landlordA)->test(AdminPlatformOverview::class)->viewData('totalArrears');

        // Landlord A's charge is fully covered by their own 12,000 surplus
        // (net 0) — landlord B's unrelated 18,000 arrears must still show in
        // full in the platform-wide total, unaffected by landlord A's credit.
        $this->assertEqualsWithDelta(18000.0, $afterTotal - $beforeTotal, 0.01);

        // And the per-landlord breakdown table keeps them separated too.
        $byLandlord = Livewire::actingAs($landlordA)->test(AdminPlatformOverview::class)->viewData('byLandlord');
        $rowA = collect($byLandlord->items())->firstWhere('landlord.id', $landlordA->id);
        $rowB = collect($byLandlord->items())->firstWhere('landlord.id', $landlordB->id);

        if ($rowA) {
            $this->assertEqualsWithDelta(
                app(PropertyFinanceService::class)->outstanding(app(PropertyFinanceService::class)->tenancyIdsFor(collect($landlordA->properties()->pluck('id')))),
                $rowA->outstanding,
                0.01
            );
        }
        if ($rowB) {
            $this->assertGreaterThanOrEqual(18000.0, $rowB->outstanding);
        }
    }

    public function test_outstanding_charge_breakdown_distinguishes_fully_and_partially_covered_charges(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        // 9,000 surplus — fully covers an 8,000 charge, partially covers a 20,000 one.
        $this->overpayToCreateAdvance($tenancy, $landlord, 20000, 29000, now()->subMonths(2));

        $fullyCovered = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->subMonth()->year, 'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(), 'amount_expected' => 8000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);
        $partiallyCovered = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $breakdown = app(PropertyFinanceService::class)->outstandingChargeBreakdown(collect([$tenancy->id]));

        $fullRow = $breakdown->first(fn (array $r) => $r['charge']->id === $fullyCovered->id);
        $partialRow = $breakdown->first(fn (array $r) => $r['charge']->id === $partiallyCovered->id);

        $this->assertEqualsWithDelta(8000.0, $fullRow['credit_applied'], 0.01);
        $this->assertEqualsWithDelta(0.0, $fullRow['net'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $partialRow['credit_applied'], 0.01);
        $this->assertEqualsWithDelta(19000.0, $partialRow['net'], 0.01);
        $this->assertEquals($tenancy->id, $fullRow['tenancy_id']);
    }

    public function test_monthly_totals_period_specific_outstanding_is_deliberately_not_netted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->overpayToCreateAdvance($tenancy, $landlord, 20000, 35000, now()->subMonth());
        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $months = Livewire::actingAs($landlord)
            ->test(AdminPlatformOverview::class, ['landlordId' => $landlord->id])
            ->viewData('months');

        $thisMonthRow = $months->firstWhere('label', now()->format('M Y'));

        $this->assertNotNull($thisMonthRow);
        $this->assertGreaterThanOrEqual(20000.0, $thisMonthRow['outstanding'], 'A period-specific trend bar must not be reduced by a different period\'s surplus.');
    }

    public function test_rent_charges_index_and_landlord_dashboard_agree_on_the_same_charges_net_amount(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->overpayToCreateAdvance($tenancy, $landlord, 20000, 26000, now()->subMonth());
        $thisMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $creditByChargeId = Livewire::actingAs($landlord)
            ->test(RentChargesIndex::class, ['year' => (int) now()->year, 'month' => (int) now()->month])
            ->viewData('creditByChargeId');

        $netFromIndex = (float) $thisMonth->amount_expected - (float) $thisMonth->amount_paid - (float) ($creditByChargeId[$thisMonth->id] ?? 0);

        $breakdown = $tenancy->outstandingChargesWithCredit();
        $netFromBreakdown = $breakdown->first(fn (array $r) => $r['charge']->id === $thisMonth->id)['net'];

        $this->assertEqualsWithDelta($netFromBreakdown, $netFromIndex, 0.01);
        $this->assertEqualsWithDelta(14000.0, $netFromIndex, 0.01, '20,000 due minus the 6,000 surplus.');
    }

    public function test_landlord_dashboard_rendering_never_changes_collection_or_payout_totals(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $service = app(DisbursementService::class);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->subMonth()->year, 'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 33000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa',
            'collection_mode' => 'platform', 'recorded_by' => $landlord->id,
        ]);
        $charge->recalculateStatus();
        $this->assertEquals('advance', $charge->status);

        $beforeOwed = $service->owed($landlord->id);

        Livewire::actingAs($landlord)->test(LandlordDashboard::class);

        $afterOwed = $service->owed($landlord->id);

        $this->assertEqualsWithDelta($beforeOwed, $afterOwed, 0.01, 'Rendering the dashboard must never itself change what is owed to the landlord.');
        $this->assertEqualsWithDelta(33000.0, (float) Payment::genuine()->where('tenancy_id', $tenancy->id)->sum('amount'), 0.01);
    }
}
