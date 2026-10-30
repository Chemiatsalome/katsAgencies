<?php

namespace Tests\Feature;

use App\Livewire\Arrears\Index as ArrearsIndex;
use App\Livewire\Payments\RecordPayment;
use App\Models\House;
use App\Models\LandlordSetting;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\PaymentAllocationService;
use App\Services\PropertyFinanceService;
use App\Services\ReportService;
use App\Services\RentScheduleService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit finding A4: RentCharge::recalculateStatus() correctly marks a
 * per-row surplus as 'advance', but nothing consumed it into a later
 * period's charge — applyLateFees() and the Arrears page both computed
 * lateness/arrears purely per-charge, so a tenant who overpaid last month
 * could still be charged a real late fee, and shown as owing more than
 * they actually did, this month. Fixed by netting a tenancy's available
 * advance credit (Tenancy::advanceCreditAvailable()) against what's
 * actually outstanding before charging a fee or displaying arrears —
 * without moving money between rows, so the original Payment audit trail
 * never changes.
 */
class AdvanceCreditNettingTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Advance Credit Test Tenant',
            'phone' => '0700222111',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
            'monthly_rent' => 20000,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_no_late_fee_is_charged_when_an_advance_credit_elsewhere_fully_covers_the_outstanding_amount(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        LandlordSetting::set($landlord->id, 'late_fee_enabled', '1');
        LandlordSetting::set($landlord->id, 'late_fee_percentage', '5');
        LandlordSetting::set($landlord->id, 'late_fee_grace_days', '0');

        $tenancy = $this->createTenancyFor($landlord);

        // Last month: paid 45,000 against a 20,000 charge — a 25,000
        // surplus, more than enough to fully cover this month's 20,000.
        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 45000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();
        $this->assertEquals('advance', $lastMonth->status);
        $this->assertEquals(25000.0, $tenancy->advanceCreditAvailable());

        // This month: 20,000 due, nothing paid yet, due date already past.
        $thisMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        app(RentScheduleService::class)->applyLateFees($landlord->id);

        $thisMonth->refresh();
        // Deliberately left unmarked (not "charged and waived") so a later
        // run re-checks it — the credit covering it today could be
        // consumed elsewhere, or reversed, before the next run.
        $this->assertNull($thisMonth->late_fee_charged_at);
        $this->assertEquals(0.0, (float) $thisMonth->late_fee_amount);
        $this->assertEquals(20000.0, (float) $thisMonth->amount_expected, 'The surplus is a net-against-arrears concept only — it must never silently alter amount_expected.');
    }

    public function test_a_partial_late_fee_is_charged_on_only_the_amount_not_covered_by_an_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        LandlordSetting::set($landlord->id, 'late_fee_enabled', '1');
        LandlordSetting::set($landlord->id, 'late_fee_percentage', '10');
        LandlordSetting::set($landlord->id, 'late_fee_grace_days', '0');

        $tenancy = $this->createTenancyFor($landlord);

        // Last month: a 2,000 surplus only — not enough to fully cover this month.
        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 22000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();

        $thisMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        app(RentScheduleService::class)->applyLateFees($landlord->id);

        $thisMonth->refresh();
        // 20,000 owed - 2,000 credit = 18,000 net outstanding; 10% of that = 1,800.
        $this->assertEquals(1800.0, (float) $thisMonth->late_fee_amount);
    }

    public function test_arrears_page_nets_the_displayed_total_against_an_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 25000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $component = Livewire::actingAs($landlord)->test(ArrearsIndex::class);
        $rows = $component->viewData('rows');
        $row = collect($rows->items())->firstWhere('tenancy.id', $tenancy->id);

        $this->assertNotNull($row);
        $this->assertEquals(15000.0, (float) $row->arrears);
    }

    /**
     * Tier 1 follow-up (2026-10-25): PropertyFinanceService::outstanding()
     * and ReportService::arrears() previously summed raw (amount_expected -
     * amount_paid) with no netting at all, disagreeing with Tenancy::balance()
     * and the Arrears page above for the exact same tenancy. These tests
     * cover that fix plus the other Tier 1 regression requirements: a
     * multi-charge payment allocation, a future charge generated while
     * credit remains, oldest-first/rounding behaviour, cross-tenancy
     * isolation, and reconciliation with the collection/payout ledger.
     */
    public function test_payment_allocation_spans_multiple_outstanding_charges_and_overflow_becomes_advance(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $older = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);
        $newer = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(1),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $payments = app(PaymentAllocationService::class)->allocate($tenancy, 45000, now(), [
            'method' => 'mpesa',
            'collection_mode' => 'platform',
            'recorded_by' => $landlord->id,
            'source' => 'system_payment',
            'status' => 'confirmed',
        ]);

        $this->assertCount(3, $payments);
        $this->assertEquals(20000.0, (float) $payments[0]->amount);
        $this->assertEquals($older->id, $payments[0]->rent_charge_id);
        $this->assertEquals(20000.0, (float) $payments[1]->amount);
        $this->assertEquals($newer->id, $payments[1]->rent_charge_id);
        $this->assertEquals(5000.0, (float) $payments[2]->amount, 'The 5,000 left over after both charges are covered becomes an advance payment.');
        $this->assertEquals($newer->id, $payments[2]->rent_charge_id);

        $older->refresh();
        $newer->refresh();
        $this->assertEquals('paid', $older->status);
        $this->assertEquals('advance', $newer->status);
        $this->assertEquals(5000.0, $tenancy->advanceCreditAvailable());
    }

    public function test_property_finance_service_outstanding_nets_against_the_tenancys_own_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 30000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        // Isolated to just this one tenancy_id — a portfolio-wide call would
        // be polluted by whatever the seeded data already owes elsewhere.
        $outstanding = app(PropertyFinanceService::class)->outstanding(collect([$tenancy->id]));

        $this->assertEquals(10000.0, $outstanding, '20,000 due minus the 10,000 surplus sitting on last month\'s charge.');
    }

    public function test_property_finance_service_outstanding_never_lets_one_tenancys_credit_offset_another_tenancys_arrears(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $creditOnlyTenancy = $this->createTenancyFor($landlord);
        $arrearsOnlyTenancy = $this->createMinimalTenancyFor($landlord, 15000);

        // Tenancy A: a pure surplus, nothing else outstanding.
        $charge = RentCharge::create([
            'tenancy_id' => $creditOnlyTenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);
        Payment::create([
            'tenancy_id' => $creditOnlyTenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 30000, 'payment_date' => now(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $charge->recalculateStatus();
        $this->assertEquals('advance', $charge->status);
        $this->assertEquals(10000.0, $creditOnlyTenancy->advanceCreditAvailable());

        // Tenancy B: genuine arrears, no credit of its own.
        RentCharge::create([
            'tenancy_id' => $arrearsOnlyTenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 15000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $outstanding = app(PropertyFinanceService::class)->outstanding(collect([$creditOnlyTenancy->id, $arrearsOnlyTenancy->id]));

        $this->assertEquals(15000.0, $outstanding, 'Tenancy A\'s 10,000 credit must never reduce tenancy B\'s unrelated 15,000 arrears.');
    }

    public function test_report_service_arrears_export_shows_gross_credit_applied_and_net_columns(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 26000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $report = app(ReportService::class)->generate('arrears', $landlord->id);

        $this->assertEquals(
            ['Tenant', 'Property / House', 'Period', 'Due Date', 'Gross Arrears', 'Advance Credit Applied', 'Net Arrears'],
            $report['columns']
        );

        $row = collect($report['rows'])->firstWhere(fn ($r) => $r[0] === $tenancy->tenant->name);

        $this->assertNotNull($row);
        $this->assertEquals(number_format(20000, 2), $row[4]);
        $this->assertEquals(number_format(6000, 2), $row[5]);
        $this->assertEquals(number_format(14000, 2), $row[6]);
    }

    public function test_a_newly_generated_future_charge_is_netted_against_existing_advance_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $lastMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $lastMonth->id,
            'amount' => 45000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $lastMonth->recalculateStatus();
        $this->assertEquals(25000.0, $tenancy->advanceCreditAvailable());

        // Simulate the same call Tenancies\Show::mount() now makes (and the
        // nightly cron already did) — generating this month's charge for
        // the first time, with the surplus already sitting on the books.
        $created = app(RentScheduleService::class)->ensureChargesForPeriod(
            null, $tenancy->house->block->property_id, (int) now()->year, (int) now()->month
        );
        $this->assertGreaterThanOrEqual(1, $created);

        $thisMonth = RentCharge::where('tenancy_id', $tenancy->id)->forPeriod((int) now()->year, (int) now()->month)->first();
        $this->assertNotNull($thisMonth);
        // Credit is a read-only netting concept only — generating the charge
        // must never silently flip its own status to 'paid'/'advance' or
        // touch amount_paid. It's 'unpaid' or 'overdue' depending purely on
        // whether today is past this month's due day.
        $this->assertContains($thisMonth->status, ['unpaid', 'overdue']);
        $this->assertEquals(0.0, (float) $thisMonth->amount_paid);

        $breakdown = $tenancy->outstandingChargesWithCredit();
        $thisMonthRow = $breakdown->first(fn (array $r) => $r['charge']->id === $thisMonth->id);

        $this->assertNotNull($thisMonthRow);
        $this->assertEquals(20000.0, $thisMonthRow['credit_applied']);
        $this->assertEquals(0.0, $thisMonthRow['net']);
    }

    public function test_outstanding_charges_with_credit_applies_oldest_first_and_rounds_safely(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $priorMonth = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonths(2)->year,
            'period_month' => now()->subMonths(2)->month,
            'due_date' => now()->subMonths(2),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $priorMonth->id,
            'amount' => 20010.33, 'payment_date' => now()->subMonths(2), 'method' => 'mpesa', 'recorded_by' => $landlord->id,
        ]);
        $priorMonth->recalculateStatus();
        // Same +/-0.01 float-precision tolerance DepositService/
        // DisbursementService already use for decimal-cast money math.
        $this->assertEqualsWithDelta(10.33, $tenancy->advanceCreditAvailable(), 0.01);

        $older = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 6.50,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);
        $newer = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDays(2),
            'amount_expected' => 20.00,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $breakdown = $tenancy->outstandingChargesWithCredit();
        $this->assertCount(2, $breakdown);

        $olderRow = $breakdown->first(fn (array $r) => $r['charge']->id === $older->id);
        $newerRow = $breakdown->first(fn (array $r) => $r['charge']->id === $newer->id);

        $this->assertEqualsWithDelta(6.50, $olderRow['credit_applied'], 0.01);
        $this->assertEqualsWithDelta(0.0, $olderRow['net'], 0.01);
        $this->assertEqualsWithDelta(3.83, $newerRow['credit_applied'], 0.01);
        $this->assertEqualsWithDelta(16.17, $newerRow['net'], 0.01);

        // The total actually applied never exceeds the original surplus,
        // and nothing is lost or double-counted to rounding.
        $this->assertEqualsWithDelta(10.33, $breakdown->sum('credit_applied'), 0.01);
    }

    public function test_advance_credit_never_reduces_genuine_collections_or_landlord_payout_owed_amount(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $service = app(DisbursementService::class);

        $before = $service->owed($landlord->id);
        $beforeCollected = $service->collectedViaMpesa($landlord->id);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 20000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 30000, 'payment_date' => now()->subMonth(), 'method' => 'mpesa',
            'collection_mode' => 'platform', 'recorded_by' => $landlord->id,
        ]);
        $charge->recalculateStatus();
        $this->assertEquals('advance', $charge->status);

        // The RentCharge ledger shows a 10,000 surplus, but the real money
        // collected — and therefore what Kats owes this landlord — is the
        // full 30,000 Safaricom actually settled. Tier 1 is a read-only
        // reporting layer; it must never reach into this ledger.
        $this->assertEquals(30000.0, round($service->collectedViaMpesa($landlord->id) - $beforeCollected, 2));
        $this->assertEquals(30000.0, round($service->owed($landlord->id) - $before, 2));
        $this->assertEquals(30000.0, (float) Payment::genuine()->where('tenancy_id', $tenancy->id)->sum('amount'));
    }

    /**
     * 2026-10-25 follow-up: RecordPayment used to require picking one
     * existing rent_charge_id, which meant (a) an amount spanning several
     * outstanding months all landed on whichever single charge was picked,
     * and (b) the whole form disappeared with "nothing to record against"
     * once nothing was outstanding — reported live when a tenant's claim
     * submitted before any RentCharge existed ended up with a null
     * rent_charge_id (see the PaymentAllocationService fix below) and a
     * landlord had no way to even attempt recording anything in the
     * meantime. RecordPayment now goes through the same
     * PaymentAllocationService::allocate() every other payment path uses,
     * so both of those are fixed at once.
     */
    public function test_record_payment_splits_a_lump_amount_across_multiple_outstanding_charges(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $older = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->subMonth()->year, 'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);
        $newer = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->subDays(2), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        Livewire::actingAs($landlord)
            ->test(RecordPayment::class, ['tenancy' => $tenancy])
            ->set('amount', '26000')
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'mpesa')
            ->call('save')
            ->assertHasNoErrors();

        $older->refresh();
        $newer->refresh();
        $this->assertEquals('paid', $older->status, 'Oldest charge first, fully covered.');
        $this->assertEquals('partial', $newer->status, 'The remaining 6,000 is correctly split onto the next charge instead of all landing on whichever single charge used to be picked.');
        $this->assertEquals(6000.0, (float) $newer->amount_paid);
    }

    public function test_record_payment_works_even_when_the_tenancy_has_no_rent_charge_at_all_yet(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenancy->rentCharges()->delete();
        $this->assertEquals(0, $tenancy->rentCharges()->count());

        Livewire::actingAs($landlord)
            ->test(RecordPayment::class, ['tenancy' => $tenancy])
            ->set('amount', '15000')
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'bank')
            ->call('save')
            ->assertHasNoErrors();

        $payment = Payment::where('tenancy_id', $tenancy->id)->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($payment->rent_charge_id, 'Must never be recorded unlinked — ensureChargesForPeriod() generates a charge to attach it to.');
        $this->assertEquals(15000.0, (float) $payment->rentCharge->amount_paid);
    }

    /**
     * The actual bug this traces back to: PaymentAllocationService::allocate()
     * (used by the M-Pesa callback and the tenant's own offline-claim form,
     * not just RecordPayment) fell back to a null rent_charge_id when a
     * tenancy had zero RentCharge rows at all — the payment stayed genuinely
     * confirmed for collection totals, but RentCharge::recalculateStatus()
     * only ever sums payments actually linked to a charge, so it silently
     * never appeared in the tenant's balance or the Rent Charges table even
     * after a landlord confirmed it. Reproduced here via the same service
     * every payment path shares, not just through RecordPayment.
     */
    public function test_payment_allocation_never_produces_an_unlinked_payment_when_no_charge_exists_yet(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenancy->rentCharges()->delete();

        $payments = app(PaymentAllocationService::class)->allocate($tenancy, 10000, now(), [
            'method' => 'bank',
            'recorded_by' => $landlord->id,
            'source' => 'tenant_submitted_offline',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        $this->assertCount(1, $payments);
        $this->assertNotNull($payments->first()->rent_charge_id);

        $payments->first()->forceFill(['status' => 'confirmed'])->save();

        $charge = RentCharge::where('tenancy_id', $tenancy->id)->first();
        $this->assertNotNull($charge);
        $this->assertEquals(10000.0, (float) $charge->amount_paid, 'Confirming it must actually reflect in the charge it was linked to.');
    }

    private function createMinimalTenancyFor(User $landlord, float $monthlyRent): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();

        $block = \App\Models\Block::create(['property_id' => $property->id, 'name' => 'Credit Isolation Block '.\Illuminate\Support\Str::random(6)]);
        $house = House::create([
            'block_id' => $block->id,
            'house_number' => 'CI-'.\Illuminate\Support\Str::random(6),
            'monthly_rent' => $monthlyRent,
            'deposit_amount' => $monthlyRent,
            'status' => 'vacant',
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant(
            Tenant::create(['name' => 'Isolation Test Tenant '.\Illuminate\Support\Str::random(6), 'phone' => '07'.random_int(10000000, 99999999)]),
            [
                'house_id' => $house->id,
                'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
                'monthly_rent' => $monthlyRent,
                'deposit_required' => $house->deposit_amount,
                'rent_due_day' => 1,
            ]
        );
    }
}
