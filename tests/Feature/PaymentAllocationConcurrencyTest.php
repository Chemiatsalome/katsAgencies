<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 2 (High): PaymentAllocationService::allocate()
 * read each outstanding RentCharge's amount_paid from an unlocked snapshot.
 * Two concurrent allocations against the same tenancy (two STK successes
 * landing close together, or an STK success racing a landlord's manual
 * RecordPayment) could both read the same pre-write "what's still due" and
 * both allocate fully against the same charge — over-crediting one period
 * while a later one wrongly stays unpaid/overdue.
 *
 * Two honest limitations, stated per the review instructions rather than
 * worked around:
 *
 * 1. PHPUnit runs single-process/single-connection, so there is no way to
 *    genuinely fire two overlapping database transactions from within one
 *    test and observe real lock contention. Nothing here claims to do
 *    that — test_two_sequential_allocations_... and
 *    test_manual_landlord_entry_and_mpesa_... are sequential correctness
 *    checks: they confirm the lock doesn't break the ordinary case, not
 *    that it resolves a race (a race, by definition, can't be produced in
 *    one process).
 * 2. This project's test suite runs on SQLite (see phpunit.xml:
 *    DB_CONNECTION=sqlite, DB_DATABASE=:memory:), and SQLite's query
 *    grammar has no FOR UPDATE clause at all — Laravel's SQLite grammar
 *    silently drops it, so lockForUpdate() is a no-op under the test
 *    suite regardless of whether the code calls it correctly.
 *    test_allocate_acquires_a_row_lock_scoped_to_the_single_tenancy below
 *    therefore skips itself (with an explanation) on every driver except
 *    mysql/pgsql. The lock was instead confirmed directly, once, with a
 *    read-only `php artisan tinker` query against this project's real
 *    MySQL connection:
 *      select * from `tenancies` where `id` = ? limit 1 for update
 *    — exactly the query allocateWithinTransaction() issues. That
 *    verification is manual, not an automated test, and is reported as
 *    such rather than folded into the suite's pass count.
 *
 * The oldest-due-first/partial/overflow-to-advance/multi-charge rules the
 * lock must not disturb are already covered in depth by
 * AdvanceCreditNettingTest (re-run as part of this phase's regression
 * check) — not duplicated here beyond the two correctness tests below.
 */
class PaymentAllocationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyWithCharge(float $amountExpected = 10000): Tenancy
    {
        $tenancy = Tenancy::active()->firstOrFail();

        // Seeded data already carries its own current-period RentCharge
        // (and possibly prior-period ones) for active tenancies — cleared
        // so this test's own single controlled charge is the only
        // *outstanding* one allocate() sees. Any pre-existing Payment rows
        // for this tenancy are left alone (deleting a RentCharge only
        // nulls their rent_charge_id, per its nullOnDelete migration — it
        // never deletes the Payment itself), which is exactly why every
        // Payment-count/sum assertion below uses a before/after delta
        // rather than an absolute value.
        RentCharge::where('tenancy_id', $tenancy->id)->delete();

        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => $amountExpected,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        return $tenancy;
    }

    /**
     * Proves the lock is real on a driver that actually supports it — see
     * limitation #2 in the class docblock for why this can't run
     * meaningfully against this suite's SQLite connection.
     */
    public function test_allocate_acquires_a_row_lock_scoped_to_the_single_tenancy(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped(
                'This test suite runs on '.DB::connection()->getDriverName().
                ', whose grammar has no FOR UPDATE clause — lockForUpdate() is a silent no-op '.
                'under this driver regardless of whether the code is correct. The lock was '.
                'instead confirmed manually against the project\'s real MySQL connection; see '.
                'this class\'s docblock and the Phase 4 implementation report.'
            );
        }

        $this->seed();
        $tenancy = $this->createTenancyWithCharge();

        DB::enableQueryLog();

        app(PaymentAllocationService::class)->allocate($tenancy, 5000, now(), [
            'method' => 'mpesa',
            'source' => 'system_payment',
            'status' => 'confirmed',
        ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $lockQueries = array_filter($log, fn ($entry) => str_contains(strtolower($entry['query']), 'for update')
            && str_contains(strtolower($entry['query']), 'tenancies'));

        $this->assertNotEmpty($lockQueries, 'allocate() must issue a lockForUpdate() query against the tenancies table.');

        $lockQuery = array_values($lockQueries)[0];
        $this->assertContains($tenancy->id, $lockQuery['bindings'], 'The lock must be scoped to this exact tenancy id.');
        $this->assertCount(1, array_unique(array_filter($lockQuery['bindings'], 'is_int')), 'Never an unscoped/blanket lock.');
    }

    /**
     * Sequential correctness check (not a concurrency test — see class
     * docblock): two allocations against the same tenancy, one right after
     * the other, must produce the exact result the existing (already
     * well-tested) allocation rules intend — the second payment landing as
     * an advance against the same, now-settled charge — not some other,
     * lock-induced distortion. This existed before the lock and must
     * survive it unchanged.
     */
    public function test_two_sequential_allocations_against_the_same_tenancy_still_allocate_correctly(): void
    {
        $this->seed();
        $tenancy = $this->createTenancyWithCharge(10000);
        $paymentCountBefore = Payment::where('tenancy_id', $tenancy->id)->count();
        $paymentSumBefore = (float) Payment::where('tenancy_id', $tenancy->id)->sum('amount');

        app(PaymentAllocationService::class)->allocate($tenancy, 10000, now(), [
            'method' => 'mpesa',
            'source' => 'system_payment',
            'status' => 'confirmed',
        ]);

        $charge = RentCharge::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('paid', $charge->fresh()->status);
        $this->assertEquals(10000.0, (float) $charge->fresh()->amount_paid);

        // The second, later payment correctly becomes an advance against
        // this same (only) charge — the existing, intended behavior
        // (Tenancy::advanceCreditAvailable() reads exactly this), not a
        // double-credit.
        app(PaymentAllocationService::class)->allocate($tenancy, 3000, now(), [
            'method' => 'mpesa',
            'source' => 'system_payment',
            'status' => 'confirmed',
        ]);

        $charge->refresh();
        $this->assertEquals('advance', $charge->status);
        $this->assertEquals(13000.0, (float) $charge->amount_paid);
        $this->assertEquals(3000.0, $tenancy->fresh()->advanceCreditAvailable());

        $this->assertEquals(2, Payment::where('tenancy_id', $tenancy->id)->count() - $paymentCountBefore);
        $this->assertEquals(13000.0, (float) Payment::where('tenancy_id', $tenancy->id)->sum('amount') - $paymentSumBefore);
    }

    /** Confirms the lock doesn't disturb the landlord-manual-entry path, which shares the exact same allocate() call. */
    public function test_manual_landlord_entry_and_mpesa_both_allocate_correctly_against_the_same_tenancy(): void
    {
        $this->seed();
        $tenancy = $this->createTenancyWithCharge(8000);
        $landlord = User::role('landlord')->firstOrFail();
        $paymentCountBefore = Payment::where('tenancy_id', $tenancy->id)->count();

        // M-Pesa-style allocation.
        app(PaymentAllocationService::class)->allocate($tenancy, 5000, now(), [
            'method' => 'mpesa',
            'source' => 'system_payment',
            'status' => 'confirmed',
        ]);

        // Landlord-manual-style allocation immediately after, same tenancy.
        app(PaymentAllocationService::class)->allocate($tenancy, 3000, now(), [
            'method' => 'cash',
            'source' => 'manual_entry',
            'recorded_by' => $landlord->id,
            'status' => 'confirmed',
        ]);

        $charge = RentCharge::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('paid', $charge->fresh()->status);
        $this->assertEquals(8000.0, (float) $charge->fresh()->amount_paid);
        $this->assertEquals(2, Payment::where('tenancy_id', $tenancy->id)->count() - $paymentCountBefore);
    }
}
