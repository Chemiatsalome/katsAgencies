<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\RentCreditAllocation;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentCreditAllocationService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2 (design approved 2026-10-25): RentCreditAllocationService built
 * and tested in isolation here — nothing in this file wires it into
 * RentCharge::recalculateStatus() or ensureChargesForPeriod(); every test
 * calls the service directly. Worked examples mirror the design
 * transcript's own numbers exactly, so a mismatch here means the
 * implementation drifted from what was actually approved.
 */
class RentCreditAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentCreditAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RentCreditAllocationService::class);
    }

    private function createTenancyFor(User $landlord, float $monthlyRent = 20000): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))
            ->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Tier2 Test Tenant '.str()->random(6),
            'phone' => '07'.random_int(10000000, 99999999),
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(3)->format('Y-m-d'),
            'monthly_rent' => $monthlyRent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    private function chargeWithPayments(Tenancy $tenancy, \DateTimeInterface|string $dueDate, float $expected, array $amounts): RentCharge
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

        foreach ($amounts as $amount) {
            Payment::create([
                'tenancy_id' => $tenancy->id,
                'rent_charge_id' => $charge->id,
                'amount' => $amount,
                'payment_date' => $date,
                'method' => 'mpesa',
            ]);
            usleep(1000); // distinct created_at ordering for the FIFO walk
        }

        $charge->recalculateStatus();

        return $charge;
    }

    private function outstandingCharge(Tenancy $tenancy, \DateTimeInterface|string $dueDate, float $expected): RentCharge
    {
        $date = \Illuminate\Support\Carbon::parse($dueDate);

        return RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => $date->year,
            'period_month' => $date->month,
            'due_date' => $date,
            'amount_expected' => $expected,
            'amount_paid' => 0,
            'status' => $date->isPast() ? 'overdue' : 'unpaid',
        ]);
    }

    // --- attribution_basis / FIFO walk -------------------------------------------------

    public function test_a_single_contributing_payment_is_exact(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);

        $this->assertEquals('exact', $this->service->attributionBasisFor($charge));
        $this->assertCount(1, $this->service->fifoSlices($charge));
    }

    public function test_p1_p2_p3_scenario_two_contributing_payments_is_reconstructed_fifo(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        // P1 = 20,000 completes the base exactly (no surplus). P2 = 3,000
        // (surplus 3,000, entirely P2's slice). P3 = 4,000 on the same
        // already-'advance' charge (surplus now 7,000: P2's 3,000 + P3's
        // 4,000) — two contributing payments.
        $charge = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [20000, 3000, 4000]);

        $slices = $this->service->fifoSlices($charge);
        $this->assertCount(2, $slices);
        $this->assertEqualsWithDelta(3000.0, $slices[0]['slice'], 0.01);
        $this->assertEqualsWithDelta(4000.0, $slices[1]['slice'], 0.01);
        $this->assertEquals('reconstructed_fifo', $this->service->attributionBasisFor($charge));
    }

    // --- applyCreditToCharge() / the two worked reconciliation examples ----------------

    public function test_worked_example_1_credit_smaller_than_target_shortfall(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);
        $this->assertEquals('advance', $source->status);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $created = $this->service->applyCreditToCharge($target);

        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(8000.0, (float) $created->first()->amount, 0.01);
        $this->assertEquals('exact', $created->first()->attribution_basis);

        $this->assertEmpty($this->service->reconciliationInvariantMismatches());
    }

    public function test_worked_example_2_credit_exceeds_target_shortfall_leaves_unallocated_remainder(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [45000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $created = $this->service->applyCreditToCharge($target);

        $this->assertCount(1, $created);
        $this->assertEqualsWithDelta(20000.0, (float) $created->first()->amount, 0.01, 'Capped at the target\'s own shortfall, not the full 25,000 available.');

        $allocatedFromSource = (float) RentCreditAllocation::where('source_rent_charge_id', $source->id)->active()->sum('amount');
        $this->assertEqualsWithDelta(20000.0, $allocatedFromSource, 0.01);
        // 25,000 surplus − 20,000 drawn = 5,000 still genuinely available.
        $this->assertEmpty($this->service->reconciliationInvariantMismatches());
    }

    public function test_applying_credit_twice_to_the_same_target_does_not_double_allocate(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $first = $this->service->applyCreditToCharge($target);
        $second = $this->service->applyCreditToCharge($target);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'Shortfall is already fully covered — idempotent, not a second draw.');
    }

    public function test_a_target_needing_more_than_one_source_charge_draws_from_both_oldest_first(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $older = $this->chargeWithPayments($tenancy, now()->subMonths(3), 20000, [25000]); // 5,000 surplus
        $newer = $this->chargeWithPayments($tenancy, now()->subMonths(2), 20000, [26000]); // 6,000 surplus
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $target->id,
            'amount' => 10000, 'payment_date' => now(), 'method' => 'mpesa',
        ]);
        $target->recalculateStatus(); // paid 10,000 directly → shortfall now 10,000, needs 10,000 of the 11,000 available

        $created = $this->service->applyCreditToCharge($target->refresh());

        $this->assertEqualsWithDelta(10000.0, (float) $created->sum('amount'), 0.01);
        $fromOlder = (float) $created->firstWhere('source_rent_charge_id', $older->id)?->amount;
        $fromNewer = (float) $created->firstWhere('source_rent_charge_id', $newer->id)?->amount;
        $this->assertEqualsWithDelta(5000.0, $fromOlder, 0.01, 'The older surplus is drawn down in full first.');
        $this->assertEqualsWithDelta(5000.0, $fromNewer, 0.01, 'Only 5,000 of the newer 6,000 surplus is needed.');
    }

    // --- reconcileSourceCapacity() — dispute cascades across sibling rows --------------

    public function test_disputing_one_payment_reverses_its_own_row_and_a_sibling_rows_now_excess_portion(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        // C: P1=15,000, P2=10,000, P3=3,000 → surplus 8,000 (P2 slice 5,000, P3 slice 3,000).
        $charge = $this->chargeWithPayments($tenancy, now()->subMonths(2), 20000, [15000, 10000, 3000]);
        $p2 = $charge->payments()->where('amount', 10000)->first();
        $p3 = $charge->payments()->where('amount', 3000)->first();

        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $rowA = RentCreditAllocation::create([
            'allocation_group_id' => 'G-test-1', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p2->id, 'source_rent_charge_id' => $charge->id,
            'target_rent_charge_id' => $target->id, 'amount' => 5000, 'attribution_basis' => 'reconstructed_fifo',
        ]);
        $rowB = RentCreditAllocation::create([
            'allocation_group_id' => 'G-test-1', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p3->id, 'source_rent_charge_id' => $charge->id,
            'target_rent_charge_id' => $target->id, 'amount' => 1000, 'attribution_basis' => 'reconstructed_fifo',
        ]);

        // Dispute P2: confirmed total drops to 15,000 + 3,000 = 18,000 (below expected 20,000).
        $p2->update(['status' => 'disputed']);
        $charge->recalculateStatus();

        $this->service->reconcileSourceCapacity($charge->refresh());

        $this->assertTrue($rowA->refresh()->isReversed(), 'P2 itself was disputed — its row must reverse outright.');
        $this->assertTrue($rowB->refresh()->isReversed(), 'P3\'s new recomputed capacity (0) is below its attributed 1,000 — must also reverse, even though P3 was never disputed.');
        $this->assertEquals('partial', $charge->status);
    }

    public function test_disputing_one_payment_leaves_a_sibling_row_untouched_when_its_capacity_still_covers_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        // Same as above but P3=10,000 instead of 3,000: removing P2 leaves
        // confirmed total 25,000 (still above expected 20,000) — P3's new
        // slice is 5,000, still >= Row B's attributed 1,000.
        $charge = $this->chargeWithPayments($tenancy, now()->subMonths(2), 20000, [15000, 10000, 10000]);
        $p2 = $charge->payments()->where('amount', 10000)->orderBy('id')->first();
        $p3 = $charge->payments()->where('amount', 10000)->orderBy('id', 'desc')->first();

        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        RentCreditAllocation::create([
            'allocation_group_id' => 'G-test-2', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p2->id, 'source_rent_charge_id' => $charge->id,
            'target_rent_charge_id' => $target->id, 'amount' => 5000, 'attribution_basis' => 'reconstructed_fifo',
        ]);
        $rowB = RentCreditAllocation::create([
            'allocation_group_id' => 'G-test-2', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p3->id, 'source_rent_charge_id' => $charge->id,
            'target_rent_charge_id' => $target->id, 'amount' => 1000, 'attribution_basis' => 'reconstructed_fifo',
        ]);

        $p2->update(['status' => 'disputed']);
        $charge->recalculateStatus();

        $this->service->reconcileSourceCapacity($charge->refresh());

        $this->assertFalse($rowB->refresh()->isReversed(), 'P3\'s recomputed slice (5,000) still covers Row B\'s 1,000 — must survive.');
    }

    // --- reconcileTargetShortfall() — target receives a direct payment while covered --

    public function test_target_receiving_a_full_direct_payment_reverses_the_credit_that_covered_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [45000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);
        $this->service->applyCreditToCharge($target);
        $this->assertEqualsWithDelta(20000.0, (float) RentCreditAllocation::where('target_rent_charge_id', $target->id)->active()->sum('amount'), 0.01);

        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $target->id,
            'amount' => 20000, 'payment_date' => now(), 'method' => 'mpesa',
        ]);
        $target->recalculateStatus();
        $this->assertEquals('paid', $target->status);

        $this->service->reconcileTargetShortfall($target->refresh());

        $this->assertEqualsWithDelta(0.0, (float) RentCreditAllocation::where('target_rent_charge_id', $target->id)->active()->sum('amount'), 0.01);

        $old = $tenancy->balance();
        $this->assertEqualsWithDelta(-25000.0, $old, 0.01, 'Target now paid in full, September\'s full 25,000 surplus genuinely still available.');
        $this->assertEmpty($this->service->reconciliationInvariantMismatches());
    }

    // --- reversal idempotency -----------------------------------------------------------

    public function test_reversing_an_already_reversed_row_is_a_no_op_not_an_error(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);
        $allocation = $this->service->applyCreditToCharge($target)->first();

        $first = $this->service->reverse($allocation, 'test reversal');
        $second = $this->service->reverse($allocation, 'test reversal again');

        $this->assertTrue($first);
        $this->assertFalse($second, 'Already reversed — second attempt affects zero rows.');
    }

    public function test_reverse_group_finishes_a_partially_auto_reversed_group_without_erroring(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = $this->chargeWithPayments($tenancy, now()->subMonths(2), 20000, [15000, 10000, 3000]);
        $p2 = $source->payments()->where('amount', 10000)->first();
        $p3 = $source->payments()->where('amount', 3000)->first();
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $rowA = RentCreditAllocation::create([
            'allocation_group_id' => 'G-partial', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p2->id, 'source_rent_charge_id' => $source->id,
            'target_rent_charge_id' => $target->id, 'amount' => 5000, 'attribution_basis' => 'reconstructed_fifo',
        ]);
        RentCreditAllocation::create([
            'allocation_group_id' => 'G-partial', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $p3->id, 'source_rent_charge_id' => $source->id,
            'target_rent_charge_id' => $target->id, 'amount' => 1000, 'attribution_basis' => 'reconstructed_fifo',
        ]);

        $this->service->reverse($rowA, 'manually reversed ahead of the group undo');

        $reversedCount = $this->service->reverseGroup('G-partial', 'undo this top-up');

        $this->assertEquals(1, $reversedCount, 'Only the still-active row counts — no error over the already-reversed one.');
    }

    // --- detection audits ----------------------------------------------------------------

    public function test_audits_find_nothing_in_a_healthy_state(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);
        $this->service->applyCreditToCharge($target);

        $this->assertEmpty($this->service->auditOverspentSources());
        $this->assertEmpty($this->service->auditExcessiveTargets());
        $this->assertEmpty($this->service->chargesEligibleForMissingAllocation());
        $this->assertEmpty($this->service->reconciliationInvariantMismatches());
    }

    public function test_charges_eligible_for_missing_allocation_finds_unapplied_credit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]);
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);

        $eligible = $this->service->chargesEligibleForMissingAllocation();

        $this->assertTrue($eligible->contains(fn (RentCharge $c) => $c->id === $target->id));
    }

    public function test_audit_overspent_sources_detects_a_manufactured_violation(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $source = $this->chargeWithPayments($tenancy, now()->subMonth(), 20000, [28000]); // surplus 8,000
        $target = $this->outstandingCharge($tenancy, now()->subDays(2), 20000);
        $payment = $source->payments()->first();

        // Manufacture a violation by writing directly, bypassing the service's own cap.
        RentCreditAllocation::create([
            'allocation_group_id' => 'G-bad', 'tenancy_id' => $tenancy->id,
            'source_payment_id' => $payment->id, 'source_rent_charge_id' => $source->id,
            'target_rent_charge_id' => $target->id, 'amount' => 9000, 'attribution_basis' => 'exact',
        ]);

        $violations = $this->service->auditOverspentSources();

        $this->assertCount(1, $violations);
        $this->assertEquals($source->id, $violations->first()['charge']->id);
    }
}
