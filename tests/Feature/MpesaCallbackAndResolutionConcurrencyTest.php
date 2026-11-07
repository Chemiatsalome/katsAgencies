<?php

namespace Tests\Feature;

use App\Http\Controllers\MpesaCallbackController;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\MpesaInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 4, final review point 3: the real callback path
 * (MpesaCallbackController::processResult()) and the admin/investigation
 * path (MpesaInvestigationService) both lock the same MpesaTransaction row
 * and both re-check isFinished() before writing — these tests demonstrate
 * that guarantee holds in both directions, and that a cancellation request
 * (metadata only, never a status change) never interferes with either.
 */
class MpesaCallbackAndResolutionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function pendingTransaction(Tenancy $tenancy, string $checkoutId, array $overrides = []): MpesaTransaction
    {
        return MpesaTransaction::create(array_merge([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => $checkoutId,
            'status' => 'pending',
        ], $overrides));
    }

    private function genuineSuccessPayload(float $amount, string $receipt): array
    {
        return [
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => now()->format('YmdHis')],
            ]],
        ];
    }

    public function test_a_genuine_successful_callback_after_a_cancellation_request_is_still_processed(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $tenant = $tenancy->tenant;
        $tenant->user_id = $tenant->user_id ?: User::factory()->create()->id;
        $tenant->save();

        $transaction = $this->pendingTransaction($tenancy, 'checkout-concurrency-cancel-then-callback-1');
        $transaction->forceFill(['created_at' => now()->subMinutes(15)])->save();

        app(MpesaInvestigationService::class)->requestCancellation($transaction, User::find($tenant->user_id));
        $this->assertNotNull($transaction->fresh()->cancellation_requested_at);
        $this->assertEquals('pending', $transaction->fresh()->status, 'A cancellation request is metadata only — it must never itself resolve the transaction.');

        app(MpesaCallbackController::class)->processResult(
            'checkout-concurrency-cancel-then-callback-1', 0, 'Success',
            $this->genuineSuccessPayload(5000, 'POST-CANCEL-REQUEST-RECEIPT'),
        );

        $transaction->refresh();
        $this->assertEquals('success', $transaction->status, 'A genuine callback must still be honoured after a cancellation request.');
        $this->assertEquals('POST-CANCEL-REQUEST-RECEIPT', $transaction->mpesa_receipt_number);
        $this->assertNotNull($transaction->payment_id);
        $this->assertNotNull($transaction->cancellation_requested_at, 'The cancellation-request record itself is history and must not be erased by the later resolution.');
    }

    public function test_admin_resolution_cannot_overwrite_an_already_successful_callback(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-concurrency-admin-vs-success-1');

        app(MpesaCallbackController::class)->processResult(
            'checkout-concurrency-admin-vs-success-1', 0, 'Success',
            $this->genuineSuccessPayload(5000, 'REAL-CALLBACK-RECEIPT-1'),
        );
        $this->assertEquals('success', $transaction->fresh()->status);
        $paymentIdBefore = $transaction->fresh()->payment_id;

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $admin, 'Attempting to overwrite a real success — must be rejected.');
        } finally {
            $transaction->refresh();
            $this->assertEquals('success', $transaction->status, 'An admin action must never downgrade an already-successful transaction.');
            $this->assertEquals('REAL-CALLBACK-RECEIPT-1', $transaction->mpesa_receipt_number);
            $this->assertEquals($paymentIdBefore, $transaction->payment_id);
        }
    }

    public function test_a_late_callback_cannot_overwrite_a_completed_admin_resolution(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-concurrency-callback-vs-admin-1');

        app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $admin, 'Tenant confirmed by phone this was declined.');
        $this->assertEquals('failed', $transaction->fresh()->status);

        $paymentCountBefore = Payment::where('tenancy_id', $tenancy->id)->count();

        // A genuinely late callback (e.g. a delayed/retried Safaricom POST)
        // arriving after the admin already closed this out — must be a
        // no-op, exactly like any other already-finished transaction.
        app(MpesaCallbackController::class)->processResult(
            'checkout-concurrency-callback-vs-admin-1', 0, 'Success',
            $this->genuineSuccessPayload(5000, 'LATE-CALLBACK-AFTER-ADMIN-1'),
        );

        $transaction->refresh();
        $this->assertEquals('failed', $transaction->status, 'A late callback must never overwrite a completed admin resolution.');
        $this->assertNull($transaction->mpesa_receipt_number);
        $this->assertEquals($paymentCountBefore, Payment::where('tenancy_id', $tenancy->id)->count(), 'No payment may be created from a callback that arrives after admin resolution.');
    }

    public function test_repeated_resolution_attempts_cannot_produce_duplicate_credits(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-concurrency-repeat-resolve-1');

        app(MpesaInvestigationService::class)->resolveAsCancelled($transaction, $admin, 'First resolution.');
        $this->assertEquals('cancelled', $transaction->fresh()->status);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $admin, 'Second, conflicting resolution attempt — must be rejected.');
        } finally {
            $this->assertEquals('cancelled', $transaction->fresh()->status, 'The first, already-applied resolution must stand — a second attempt must never flip it.');
        }
    }

    public function test_a_duplicate_real_callback_after_admin_resolution_still_does_not_double_credit(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $charge = \App\Models\RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 5000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'ADMIN-LINKED-RECEIPT-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-concurrency-admin-success-then-callback-1');
        app(MpesaInvestigationService::class)->resolveAsSuccessful($transaction, $admin, 'ADMIN-LINKED-RECEIPT-1', 'Found on bank statement.');
        $this->assertEquals('success', $transaction->fresh()->status);

        $paymentCountBefore = Payment::where('tenancy_id', $tenancy->id)->count();

        // Safaricom's real callback for the same push finally arrives after
        // the admin already closed it out — must not create a second credit.
        app(MpesaCallbackController::class)->processResult(
            'checkout-concurrency-admin-success-then-callback-1', 0, 'Success',
            $this->genuineSuccessPayload(5000, 'ADMIN-LINKED-RECEIPT-1'),
        );

        $this->assertEquals($paymentCountBefore, Payment::where('tenancy_id', $tenancy->id)->count());
        $this->assertEquals($payment->id, $transaction->fresh()->payment_id, 'Must still point at the admin-linked payment, not a new one.');
    }
}
