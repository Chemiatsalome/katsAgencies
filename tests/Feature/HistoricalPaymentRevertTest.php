<?php

namespace Tests\Feature;

use App\Livewire\RentCharges\HistoricalReview;
use App\Livewire\RentCharges\Index as RentChargesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\LandlordSetting;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\RentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class HistoricalPaymentRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_outstanding_excludes_charges_not_yet_due(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();

        $future = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->addMonths(2)->year,
            'period_month' => now()->addMonths(2)->month,
            'due_date' => now()->addMonths(2),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        $due = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->subDay(),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $ids = RentCharge::outstanding()->pluck('id');

        $this->assertFalse($ids->contains($future->id), 'A not-yet-due charge must not count as outstanding.');
        $this->assertTrue($ids->contains($due->id), 'An already-due unpaid charge must still count as outstanding.');
    }

    public function test_ensure_charges_for_period_marks_pre_financial_start_months_as_opening_balance(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlordId = $tenancy->house->block->property->landlord_id;

        // No explicit setting — default cutoff is the start of the current month.
        // A month several months back should be auto-settled as an opening balance.
        $targetDate = now()->subMonths(3);
        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, null, $targetDate->year, $targetDate->month);

        $charge = RentCharge::where('tenancy_id', $tenancy->id)
            ->where('period_year', $targetDate->year)
            ->where('period_month', $targetDate->month)
            ->firstOrFail();

        $this->assertEquals('paid', $charge->status);
        $payment = $charge->payments->first();
        $this->assertEquals('opening_balance', $payment->source);
        $this->assertTrue($charge->isRevertible());
    }

    public function test_ensure_charges_for_period_leaves_current_month_as_real_arrears(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlordId = $tenancy->house->block->property->landlord_id;

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, null, now()->year, now()->month);

        $charge = RentCharge::where('tenancy_id', $tenancy->id)
            ->where('period_year', now()->year)
            ->where('period_month', now()->month)
            ->firstOrFail();

        $this->assertContains($charge->status, ['unpaid', 'overdue']);
        $this->assertEquals(0, (float) $charge->amount_paid);
        $this->assertFalse($charge->isRevertible());
    }

    public function test_landlord_can_override_the_financial_start_date_to_extend_the_historical_window(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlordId = $tenancy->house->block->property->landlord_id;

        // Push the cutoff forward to next month — so even *this* month should
        // now be treated as historical/settled by default.
        LandlordSetting::set($landlordId, 'financial_start_date', now()->addMonth()->startOfMonth()->format('Y-m-d'));

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, null, now()->year, now()->month);

        $charge = RentCharge::where('tenancy_id', $tenancy->id)
            ->where('period_year', now()->year)
            ->where('period_month', now()->month)
            ->firstOrFail();

        $this->assertEquals('paid', $charge->status);
        $this->assertEquals('opening_balance', $charge->payments->first()->source);
    }

    public function test_revert_historical_payment_succeeds_for_opening_balance_source(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => 2025,
            'period_month' => 3,
            'due_date' => '2025-03-01',
            'amount_expected' => 14000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'rent_charge_id' => $charge->id,
            'amount' => 14000,
            'payment_date' => '2025-03-01',
            'method' => 'other',
            'notes' => RentCharge::ASSUMED_PAID_NOTE,
            'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();
        $this->assertEquals('paid', $charge->fresh()->status);

        $reverted = app(RentScheduleService::class)->revertHistoricalPayment($charge->fresh(), $landlord);

        $this->assertTrue($reverted);
        $charge->refresh();
        $this->assertNotEquals('paid', $charge->status);
        $this->assertEquals(0, (float) $charge->amount_paid);
    }

    public function test_revert_historical_payment_refuses_a_genuine_system_payment(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => 14000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'rent_charge_id' => $charge->id,
            'amount' => 14000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'notes' => 'Paid via M-Pesa (tenant self-service)',
            'source' => 'system_payment',
        ]);
        $charge->recalculateStatus();

        $reverted = app(RentScheduleService::class)->revertHistoricalPayment($charge->fresh(), $landlord);

        $this->assertFalse($reverted);
        $this->assertEquals('paid', $charge->fresh()->status);
        $this->assertDatabaseHas('payments', ['rent_charge_id' => $charge->id, 'source' => 'system_payment']);
    }

    public function test_revert_historical_payment_refuses_a_manual_entry(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => 14000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'rent_charge_id' => $charge->id,
            'amount' => 14000,
            'payment_date' => now(),
            'method' => 'cash',
            'source' => 'manual_entry',
        ]);
        $charge->recalculateStatus();

        $reverted = app(RentScheduleService::class)->revertHistoricalPayment($charge->fresh(), $landlord);

        $this->assertFalse($reverted);
        $this->assertEquals('paid', $charge->fresh()->status);
    }

    public function test_revert_historical_payment_refuses_a_charge_with_mixed_sources(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => 2025,
            'period_month' => 4,
            'due_date' => '2025-04-01',
            'amount_expected' => 14000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 7000,
            'payment_date' => '2025-04-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 7000,
            'payment_date' => '2025-04-15', 'method' => 'cash', 'source' => 'manual_entry',
        ]);
        $charge->recalculateStatus();
        $this->assertFalse($charge->fresh()->isRevertible());

        $reverted = app(RentScheduleService::class)->revertHistoricalPayment($charge->fresh(), $landlord);
        $this->assertFalse($reverted);
    }

    public function test_revert_creates_a_financial_corrections_audit_log_entry(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->with('house.block.property', 'tenant')->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => 2025,
            'period_month' => 5,
            'due_date' => '2025-05-01',
            'amount_expected' => 14000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 14000,
            'payment_date' => '2025-05-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();

        app(RentScheduleService::class)->revertHistoricalPayment($charge->fresh(), $landlord);

        $entry = Activity::where('log_name', 'financial_corrections')->latest()->first();
        $this->assertNotNull($entry);
        $this->assertEquals($landlord->id, $entry->causer_id);
        $this->assertEquals($tenancy->tenant->name, $entry->getExtraProperty('tenant'));
        $this->assertEquals('May 2025', $entry->getExtraProperty('month'));
        $this->assertEquals('opening_balance', $entry->getExtraProperty('previous_source'));
    }

    public function test_rent_charges_index_revert_action_is_authorized_and_works(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 6,
            'due_date' => '2025-06-01', 'amount_expected' => 14000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 14000,
            'payment_date' => '2025-06-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();

        Livewire::actingAs($landlord)
            ->test(RentChargesIndex::class)
            ->set('year', 2025)
            ->set('month', 6)
            ->call('revertHistoricalPayment', $charge->id)
            ->assertHasNoErrors();

        $this->assertNotEquals('paid', $charge->fresh()->status);
    }

    public function test_other_landlord_cannot_revert_a_charge_they_dont_own(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();
        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 7,
            'due_date' => '2025-07-01', 'amount_expected' => 14000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 14000,
            'payment_date' => '2025-07-01', 'method' => 'other', 'source' => 'opening_balance',
        ]);
        $charge->recalculateStatus();

        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);

        Livewire::actingAs($otherLandlord)
            ->test(RentChargesIndex::class)
            ->call('revertHistoricalPayment', $charge->id)
            ->assertForbidden();

        $this->assertEquals('paid', $charge->fresh()->status);
    }

    public function test_bulk_revert_only_touches_selected_and_revertible_rows(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::active()->firstOrFail();

        $revertibleA = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 1,
            'due_date' => '2025-01-01', 'amount_expected' => 14000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create(['tenancy_id' => $tenancy->id, 'rent_charge_id' => $revertibleA->id, 'amount' => 14000, 'payment_date' => '2025-01-01', 'method' => 'other', 'source' => 'opening_balance']);
        $revertibleA->recalculateStatus();

        $revertibleB = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 2,
            'due_date' => '2025-02-01', 'amount_expected' => 14000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create(['tenancy_id' => $tenancy->id, 'rent_charge_id' => $revertibleB->id, 'amount' => 14000, 'payment_date' => '2025-02-01', 'method' => 'other', 'source' => 'seeded_data']);
        $revertibleB->recalculateStatus();

        // A protected charge that must never be touched even if its id were
        // somehow smuggled into the selection.
        $protected = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => 2025, 'period_month' => 8,
            'due_date' => '2025-08-01', 'amount_expected' => 14000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create(['tenancy_id' => $tenancy->id, 'rent_charge_id' => $protected->id, 'amount' => 14000, 'payment_date' => '2025-08-01', 'method' => 'cash', 'source' => 'manual_entry']);
        $protected->recalculateStatus();

        Livewire::actingAs($landlord)
            ->test(HistoricalReview::class)
            ->set('year', 2025)
            ->set('selected', [$revertibleA->id, $revertibleB->id, $protected->id])
            ->call('revertSelected');

        $this->assertNotEquals('paid', $revertibleA->fresh()->status);
        $this->assertNotEquals('paid', $revertibleB->fresh()->status);
        $this->assertEquals('paid', $protected->fresh()->status, 'A genuine manual-entry payment must never be reverted by the bulk action.');
    }

    public function test_financial_start_date_setting_can_be_saved(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->set('tab', 'financial')
            ->set('financial_start_date', '2026-01-01')
            ->call('saveFinancial')
            ->assertHasNoErrors();

        $this->assertEquals('2026-01-01', LandlordSetting::get($landlord->id, 'financial_start_date'));
    }
}
