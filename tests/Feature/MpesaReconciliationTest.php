<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers two audit findings: (A5) a failed/cancelled M-Pesa callback never
 * notified anyone — the tenant only found out if their own tab was still
 * open polling. (A6) a transaction whose callback never arrived at all sat
 * 'pending' forever; the client-side 3-minute timeout only gives up
 * locally, it never actually asks Safaricom what happened. Fixed with a
 * scheduled command (mpesa:reconcile-pending) that queries Safaricom's STK
 * status endpoint directly for anything stuck, and a shared
 * MpesaCallbackController::processResult() so a result discovered that way
 * is applied through the exact same idempotency/locking path a real
 * callback would use.
 */
class MpesaReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function configureMpesa(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.callback_url' => 'https://example.test/mpesa/callback',
            'services.mpesa.callback_secret' => 'test-callback-secret',
        ]);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
        ]);
    }

    public function test_a_cancelled_callback_notifies_the_tenant(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant')->firstOrFail();
        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $user->id]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-cancel-notify-1',
            'status' => 'pending',
        ]);

        config(['services.mpesa.callback_secret' => 'test-callback-secret']);

        $this->postJson(route('mpesa.callback', ['token' => 'test-callback-secret']), [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-x',
                    'CheckoutRequestID' => 'checkout-cancel-notify-1',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ])->assertOk();

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'rent_payment_failed')
                ->exists()
        );

        $transaction->refresh();
        $this->assertEquals('cancelled', $transaction->status);
    }

    public function test_reconciliation_resolves_a_stuck_transaction_the_query_reports_as_failed(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant')->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResultCode' => 1,
                'ResultDesc' => 'The balance on the customer\'s account is insufficient.',
            ]),
        ]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-reconcile-failed-1',
            'status' => 'pending',
                    ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->artisan('mpesa:reconcile-pending')->assertExitCode(0);

        $transaction->refresh();
        $this->assertEquals('failed', $transaction->status);
        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'rent_payment_failed')
                ->exists()
        );
    }

    public function test_reconciliation_flags_a_missed_successful_callback_for_manual_verification_instead_of_crediting(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->with('tenant', 'house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $paymentCountBefore = \App\Models\Payment::where('tenancy_id', $tenancy->id)->count();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-reconcile-missed-1',
            'status' => 'pending',
        ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->artisan('mpesa:reconcile-pending')->assertExitCode(0);

        $transaction->refresh();
        // Never silently credited — no receipt number exists to record.
        $this->assertNull($transaction->payment_id);
        $this->assertEquals($paymentCountBefore, \App\Models\Payment::where('tenancy_id', $tenancy->id)->count());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'mpesa_callback_missed')
                ->exists()
        );
    }

    public function test_reconciliation_times_out_a_transaction_safaricom_has_no_answer_for_after_ten_minutes(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([], 500),
        ]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-reconcile-timeout-1',
            'status' => 'pending',
                    ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(11)])->save();

        $this->artisan('mpesa:reconcile-pending')->assertExitCode(0);

        $this->assertEquals('timeout', $transaction->fresh()->status);
    }

    public function test_reconciliation_leaves_a_recently_stuck_transaction_alone_when_safaricom_has_no_answer_yet(): void
    {
        $this->configureMpesa();
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([], 500),
        ]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-reconcile-too-soon-1',
            'status' => 'pending',
                    ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(3)])->save();

        $this->artisan('mpesa:reconcile-pending')->assertExitCode(0);

        $this->assertEquals('pending', $transaction->fresh()->status);
    }
}
