<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DepositPayment;
use App\Models\MpesaStatusQuery;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\MpesaInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 4: the service layer behind admin investigation and
 * query interpretation. Covers the clarification-1 correction specifically
 * (a query-confirmed success for rent/deposit must never auto-credit — it
 * carries no CallbackMetadata), the idempotent/no-duplicate-notification
 * escalation behaviour from clarification 2, provider-response sanitisation
 * from clarification 4, and the admin-authorisation + locking/audit-trail
 * safeguards from clarification 5.
 */
class MpesaInvestigationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function configureMpesa(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
        ]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
        ]);
    }

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

    public function test_a_query_confirmed_success_for_rent_is_escalated_not_credited(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-query-success-rent-1',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                // Deliberately no CallbackMetadata — exactly what a real STK
                // status query returns, unlike a real callback.
            ]),
        ]);

        $paymentCountBefore = Payment::where('tenancy_id', $tenancy->id)->count();
        $transaction = $this->pendingTransaction($tenancy, 'checkout-query-success-rent-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('escalated_manual_verification', $query->interpreted_as);
        $transaction->refresh();
        $this->assertEquals('pending', $transaction->status, 'Must stay unresolved — no receipt/amount to credit from.');
        $this->assertEquals(MpesaTransaction::OUTCOME_ESCALATED, $transaction->investigation_outcome);
        $this->assertNull($transaction->payment_id);
        $this->assertEquals($paymentCountBefore, Payment::where('tenancy_id', $tenancy->id)->count());
    }

    public function test_a_query_confirmed_success_for_an_sms_topup_credits_directly(): void
    {
        $this->configureMpesa();
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-query-success-sms-1',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $transaction = MpesaTransaction::create([
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 500,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-query-success-sms-1',
            'status' => 'pending',
        ]);

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'reconciliation');

        $this->assertEquals('sms_topup_credited', $query->interpreted_as);
        $this->assertEquals('success', $transaction->fresh()->status, 'An SMS top-up carries no receipt-uniqueness guard, so a query-confirmed success is safe to credit directly (preserved from before this fix).');
    }

    public function test_a_query_confirmed_decline_applies_without_escalation(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-query-cancel-1',
                'ResultCode' => 1032,
                'ResultDesc' => 'Request cancelled by user',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-query-cancel-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'tenant_cancellation');

        $this->assertEquals('provider_result_applied', $query->interpreted_as);
        $this->assertEquals('cancelled', $transaction->fresh()->status, 'A decline/cancellation needs no metadata, so it is safe to auto-apply from a query.');
    }

    public function test_an_inconclusive_query_is_recorded_in_history_without_mutating_the_transaction(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([], 500),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-inconclusive-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'reconciliation');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->last_queried_at);
    }

    /**
     * Final review, point 1 (explicitly requested case): a non-"0"
     * query-level ResponseCode means the query request itself wasn't
     * cleanly processed — any ResultCode sitting in that same payload
     * (even a definitive-looking one) must not be trusted or applied.
     */
    public function test_a_nonzero_query_level_response_code_with_a_nonzero_result_code_is_inconclusive(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '1',
                'ResponseDescription' => 'The request could not be processed.',
                'CheckoutRequestID' => 'checkout-bad-envelope-1',
                'ResultCode' => 1,
                'ResultDesc' => 'The balance on the customer\'s account is insufficient.',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-bad-envelope-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status, 'A non-zero query-level ResponseCode must never let the enclosed ResultCode apply.');
    }

    /** Safaricom's own "still processing" response for an unresolved push carries errorCode/errorMessage instead of ResponseCode/ResultCode at all — already naturally inconclusive, covered for completeness. */
    public function test_a_still_processing_error_shaped_response_is_inconclusive(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'requestId' => 'some-request-id',
                'errorCode' => '500.001.1001',
                'errorMessage' => 'The transaction is being processed',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-still-processing-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'reconciliation');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    /** A well-formed envelope that happens to describe a different transaction must never be applied to this one. */
    public function test_a_mismatched_checkout_request_id_is_inconclusive_even_with_a_valid_result_code(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-SOME-OTHER-TRANSACTION',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-mismatch-id-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->investigation_outcome, 'Must not even escalate from a response that isn\'t confirmed to be about this transaction.');
    }

    /**
     * Second final-review round: 1001 ("Unable to lock subscriber, a
     * transaction is already in process for the current subscriber") is a
     * concurrency/locking condition at Safaricom's end, not a verified
     * statement about this specific CheckoutRequestID's own outcome — it
     * must never be treated as a definitive decline.
     */
    public function test_result_code_1001_subscriber_locked_is_inconclusive_not_a_definitive_failure(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-code-1001-1',
                'ResultCode' => 1001,
                'ResultDesc' => 'Unable to lock subscriber, a transaction is already in process for the current subscriber',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-code-1001-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status, '1001 describes a subscriber-lock conflict, not this transaction\'s own fate — must not be applied.');
        $this->assertEquals('1001', $query->result_code, 'Still recorded for audit even though not acted on.');
    }

    /** 9999 has no single, confidently-documented STK-query meaning — treated as unrecognised rather than guessed at. */
    public function test_result_code_9999_is_inconclusive_not_a_definitive_outcome(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-code-9999-1',
                'ResultCode' => 9999,
                'ResultDesc' => 'Request failed',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-code-9999-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    /** 2001's STK-specific meaning could not be confidently verified (its commonly-remembered association is with a different API product) — treated as unrecognised. */
    public function test_result_code_2001_is_inconclusive_not_a_definitive_outcome(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-code-2001-1',
                'ResultCode' => 2001,
                'ResultDesc' => 'Invalid Initiator Information',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-code-2001-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    /** Only a recognised, documented STK ResultCode is treated as definitive — an unknown one is inconclusive, not guessed at as a failure. */
    public function test_an_unrecognised_result_code_is_inconclusive(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-unknown-code-1',
                'ResultCode' => 424242,
                'ResultDesc' => 'Some undocumented code this app has never seen before.',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-unknown-code-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals('inconclusive', $query->interpreted_as);
        $this->assertEquals('pending', $transaction->fresh()->status);
        // Still recorded for audit, even though not acted on.
        $this->assertEquals('424242', $query->result_code);
    }

    public function test_every_query_attempt_is_recorded_as_separate_history_not_a_single_overwritten_field(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([], 500),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-history-1');
        $service = app(MpesaInvestigationService::class);

        $service->queryAndInterpret($transaction, 'reconciliation');
        $service->queryAndInterpret($transaction, 'reconciliation');
        $service->queryAndInterpret($transaction, 'admin_requery');

        $this->assertEquals(3, MpesaStatusQuery::where('mpesa_transaction_id', $transaction->id)->count());
        $this->assertEquals(1, MpesaStatusQuery::where('mpesa_transaction_id', $transaction->id)->where('source', 'admin_requery')->count());
    }

    public function test_sensitive_keys_are_stripped_from_the_stored_raw_response(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-sanitize-1',
                'ResultCode' => 1032,
                'ResultDesc' => 'Request cancelled by user',
                // Not a real Safaricom field — defensive coverage per
                // clarification 4, in case any provider response ever did
                // echo back something credential-shaped.
                'Password' => 'should-never-be-stored',
                'AccessToken' => 'should-never-be-stored-either',
            ]),
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-sanitize-1');

        $query = app(MpesaInvestigationService::class)->queryAndInterpret($transaction, 'admin_requery');

        $this->assertArrayNotHasKey('Password', $query->raw_response);
        $this->assertArrayNotHasKey('AccessToken', $query->raw_response);
        $this->assertEquals('1032', $query->result_code);
    }

    public function test_escalation_is_idempotent_and_does_not_duplicate_notifications(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant', 'house.block.property')->firstOrFail();
        $transaction = $this->pendingTransaction($tenancy, 'checkout-escalate-twice-1');

        $service = app(MpesaInvestigationService::class);
        $service->escalateForManualVerification($transaction, null, 'First escalation.');
        $service->escalateForManualVerification($transaction, null, 'Second escalation attempt — must not duplicate.');

        $this->assertEquals(
            1,
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'mpesa_payment_needs_verification')
                ->count()
        );
        $this->assertEquals('First escalation.', $transaction->fresh()->investigation_notes, 'The first escalation\'s notes must not be overwritten by a no-op repeat.');
    }

    public function test_escalation_never_blocks_a_later_genuine_callback(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $transaction = $this->pendingTransaction($tenancy, 'checkout-escalate-then-callback-1');

        app(MpesaInvestigationService::class)->escalateForManualVerification($transaction, null, 'No answer yet.');
        $this->assertEquals('pending', $transaction->fresh()->status);

        app(\App\Http\Controllers\MpesaCallbackController::class)->processResult(
            'checkout-escalate-then-callback-1', 0, 'Success',
            ['CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 5000],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'LATE-RECEIPT-1'],
                ['Name' => 'TransactionDate', 'Value' => now()->format('YmdHis')],
            ]]],
        );

        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $this->assertNotNull($transaction->payment_id);
    }

    public function test_admin_can_resolve_a_transaction_as_failed_with_an_audit_trail(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant')->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-fail-1');

        $resolved = app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $admin, 'Tenant confirmed by phone the PIN prompt was declined.');

        $this->assertEquals('failed', $resolved->status);
        $this->assertEquals($admin->id, $resolved->investigated_by);
        $this->assertNotNull($resolved->investigated_at);
        $this->assertEquals(MpesaTransaction::OUTCOME_RESOLVED_FAILED, $resolved->investigation_outcome);
    }

    public function test_non_admin_cannot_resolve_a_transaction(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $nonAdmin = User::factory()->create();
        $nonAdmin->syncRoles(['tenant']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-authz-1');

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $nonAdmin, 'Attempted without admin role.');
    }

    public function test_resolving_an_already_finished_transaction_throws_instead_of_overwriting_it(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-already-done-1', [
            'status' => 'success',
            'mpesa_receipt_number' => 'ALREADY-REAL',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(MpesaInvestigationService::class)->resolveAsCancelled($transaction, $admin, 'Should never apply.');
    }

    public function test_admin_resolve_as_successful_links_to_an_already_confirmed_payment(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant')->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 5000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'FOUND-ON-STATEMENT-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-link-success-1');

        $resolved = app(MpesaInvestigationService::class)->resolveAsSuccessful(
            $transaction, $admin, 'FOUND-ON-STATEMENT-1', 'Landlord found it on their bank statement.'
        );

        $this->assertEquals('success', $resolved->status);
        $this->assertEquals($payment->id, $resolved->payment_id);
        $this->assertEquals(MpesaTransaction::OUTCOME_RESOLVED_SUCCESSFUL, $resolved->investigation_outcome);
    }

    public function test_admin_resolve_as_successful_fails_without_a_matching_confirmed_payment(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-link-missing-1');

        $this->expectException(\InvalidArgumentException::class);

        app(MpesaInvestigationService::class)->resolveAsSuccessful(
            $transaction, $admin, 'NO-SUCH-REFERENCE', 'Nothing was actually recorded for this.'
        );
    }

    public function test_admin_resolve_as_successful_works_for_deposit_transactions_too(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $depositPayment = DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 20000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'bank', 'reference_number' => 'DEP-FOUND-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-link-deposit-1', ['purpose' => 'deposit', 'amount' => 20000]);

        $resolved = app(MpesaInvestigationService::class)->resolveAsSuccessful(
            $transaction, $admin, 'DEP-FOUND-1', 'Confirmed against the bank statement.'
        );

        $this->assertEquals('success', $resolved->status);
        $this->assertEquals($depositPayment->id, $resolved->deposit_payment_id);
    }

    /** Final review, point 2: a reference string matching an unrelated, differently-sized payment must never be accepted as proof this attempt succeeded. */
    public function test_admin_resolve_as_successful_rejects_a_confirmed_payment_with_a_different_amount(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 500, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            // A genuine but unrelated KSh 500 payment that happens to carry
            // the same reference string by coincidence or typo.
            'amount' => 500, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'COINCIDENTAL-REF-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $transaction = $this->pendingTransaction($tenancy, 'checkout-admin-amount-mismatch-1', ['amount' => 5000]);

        $this->expectException(\InvalidArgumentException::class);

        app(MpesaInvestigationService::class)->resolveAsSuccessful(
            $transaction, $admin, 'COINCIDENTAL-REF-1', 'Should be rejected — amounts do not match.'
        );
    }

    /** Final review, point 2: one real payment can vouch for exactly one stuck transaction, never several. */
    public function test_admin_resolve_as_successful_rejects_a_payment_already_linked_to_a_different_transaction(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 5000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'ALREADY-CLAIMED-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $firstTransaction = $this->pendingTransaction($tenancy, 'checkout-admin-first-claim-1');
        app(MpesaInvestigationService::class)->resolveAsSuccessful($firstTransaction, $admin, 'ALREADY-CLAIMED-1', 'First, legitimate resolution.');
        $this->assertEquals($firstTransaction->id, $payment->fresh()->mpesa_transaction_id);

        $secondTransaction = $this->pendingTransaction($tenancy, 'checkout-admin-second-claim-1');

        $this->expectException(\InvalidArgumentException::class);

        app(MpesaInvestigationService::class)->resolveAsSuccessful(
            $secondTransaction, $admin, 'ALREADY-CLAIMED-1', 'Should be rejected — already vouching for the first transaction.'
        );
    }
}
