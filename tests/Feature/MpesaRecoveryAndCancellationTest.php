<?php

namespace Tests\Feature;

use App\Livewire\Tenant\PayDeposit;
use App\Livewire\Tenant\PayRent;
use App\Models\AppNotification;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\MpesaInvestigationService;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 4: recovering a page-reload-lost STK push, the hard
 * retry block while one is unresolved, and the tenant-facing cancellation
 * request. Mirrors Finding 1's existing security-test pattern (bare PHP
 * instantiation for anything that must bypass Livewire's own test-harness
 * snapshot re-hydration) wherever a scenario needs it; everything else is
 * driven through the real Livewire test harness, matching how a browser
 * actually reaches these methods.
 */
class MpesaRecoveryAndCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        $landlordId = $tenancy->house->block->property->landlord_id;
        app(PaymentGatewayService::class)->confirmCollectionMethod($landlordId, 'platform');

        return $user;
    }

    public function test_reloading_the_page_restores_a_single_unresolved_rent_transaction(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-recover-1',
            'status' => 'pending',
        ]);

        // A fresh component instance is exactly what a page reload produces
        // — mount() runs again with nothing but the authenticated session.
        $component = Livewire::actingAs($user)->test(PayRent::class);

        $component->assertSet('pendingTransactionId', $transaction->id);
    }

    public function test_reloading_the_page_restores_a_single_unresolved_deposit_transaction(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 23000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-recover-dep-1',
            'status' => 'pending',
        ]);

        $component = Livewire::actingAs($user)->test(PayDeposit::class);

        $component->assertSet('pendingTransactionId', $transaction->id);
    }

    public function test_reload_does_not_restore_an_already_resolved_transaction(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-resolved-1',
            'status' => 'success',
            'mpesa_receipt_number' => 'ALREADY-DONE',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->assertSet('pendingTransactionId', null);
    }

    /** A legacy 'timeout' row (predating this fix) is still unresolved and still restorable. */
    public function test_reload_restores_a_legacy_timeout_row_as_unresolved(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-legacy-timeout-1',
            'status' => 'timeout',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->assertSet('pendingTransactionId', $transaction->id);
    }

    public function test_pay_is_hard_blocked_while_an_unresolved_rent_transaction_exists(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-block-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasErrors('phone');

        // No second transaction was created — the only row is still the original.
        $this->assertEquals(1, MpesaTransaction::where('tenancy_id', $tenancy->id)->count());
    }

    public function test_pay_deposit_is_hard_blocked_while_an_unresolved_deposit_transaction_exists(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 10000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-block-dep-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '10000')
            ->call('pay')
            ->assertHasErrors('phone');

        $this->assertEquals(1, MpesaTransaction::where('tenancy_id', $tenancy->id)->where('purpose', 'deposit')->count());
    }

    /** An unresolved rent transaction must never block a deposit attempt, or vice versa — the two ledgers stay independent. */
    public function test_an_unresolved_rent_transaction_does_not_block_a_deposit_payment_attempt(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.callback_url' => 'https://example.test/mpesa/callback',
            'services.mpesa.callback_secret' => 'test-callback-secret',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => \Illuminate\Support\Facades\Http::response([
                'MerchantRequestID' => 'merchant-indep-1',
                'CheckoutRequestID' => 'checkout-indep-dep-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-indep-rent-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '10000')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertEquals(1, MpesaTransaction::where('tenancy_id', $tenancy->id)->where('purpose', 'deposit')->count());
    }

    public function test_offline_claim_form_does_not_open_while_an_unresolved_transaction_exists(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-block-offline-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->call('openOfflineForm')
            ->assertSet('showOfflineForm', false);
    }

    /** openOfflineForm() is only a UI toggle — submitOfflinePayment() itself must refuse even if somehow reached directly. */
    public function test_submit_offline_payment_is_blocked_even_if_the_form_toggle_is_bypassed(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-block-offline-2',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->set('showOfflineForm', true)
            ->set('offline_method', 'cash')
            ->set('offline_amount', '4000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->call('submitOfflinePayment')
            ->assertHasErrors('offline_amount');

        $this->assertDatabaseMissing('payments', ['tenancy_id' => $tenancy->id, 'source' => 'tenant_submitted_offline']);
    }

    public function test_pay_is_allowed_again_once_the_unresolved_transaction_resolves(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.callback_url' => 'https://example.test/mpesa/callback',
            'services.mpesa.callback_secret' => 'test-callback-secret',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => \Illuminate\Support\Facades\Http::response([
                'MerchantRequestID' => 'merchant-retry-1',
                'CheckoutRequestID' => 'checkout-retry-after-resolve-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $old = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-old-resolved-1',
            'status' => 'failed',
            'result_code' => 1,
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertEquals(2, MpesaTransaction::where('tenancy_id', $tenancy->id)->count());
    }

    public function test_tenant_can_request_cancellation_after_ten_minutes(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-cancel-req-1',
            'status' => 'pending',
        ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(11)])->save();

        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => \Illuminate\Support\Facades\Http::response([], 500),
        ]);
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->call('requestCancellation', $transaction->id)
            ->assertHasNoErrors();

        $transaction->refresh();
        $this->assertNotNull($transaction->cancellation_requested_at);
        $this->assertEquals($user->id, $transaction->cancellation_requested_by);
        $this->assertEquals('pending', $transaction->status, 'A cancellation request is not a real Safaricom cancellation — status must stay unresolved.');

        $landlord = $tenancy->house->block->property->landlord;
        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'mpesa_cancellation_requested')
                ->exists()
        );
    }

    public function test_tenant_cannot_request_cancellation_before_ten_minutes(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-too-soon-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->call('requestCancellation', $transaction->id)
            ->assertHasErrors('phone');

        $this->assertNull($transaction->fresh()->cancellation_requested_at);
    }

    public function test_cancellation_request_cannot_be_submitted_twice(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-double-cancel-1',
            'status' => 'pending',
            'cancellation_requested_at' => now()->subMinutes(5),
            'cancellation_requested_by' => $user->id,
        ]);
        $transaction->forceFill(['created_at' => now()->subMinutes(15)])->save();

        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => \Illuminate\Support\Facades\Http::response([], 500),
        ]);
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
        ]);

        Livewire::actingAs($user)->test(PayRent::class)
            ->call('requestCancellation', $transaction->id)
            ->assertHasErrors('phone');
    }

    /** Final review, point 4: an escalated transaction is still unresolved and must still hard-block a new M-Pesa attempt for the same tenancy+purpose. */
    public function test_an_escalated_transaction_still_blocks_a_new_pay_attempt(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-escalated-block-rent-1',
            'status' => 'pending',
        ]);
        app(MpesaInvestigationService::class)->escalateForManualVerification($transaction, null, 'No answer after repeated queries.');
        $this->assertTrue($transaction->fresh()->needsManualVerification());

        Livewire::actingAs($user)->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasErrors('phone');

        $this->assertEquals(1, MpesaTransaction::where('tenancy_id', $tenancy->id)->count());
    }

    /** Final review, point 4: an escalated transaction must also block the offline-claim submission, not just a new STK push. */
    public function test_an_escalated_transaction_still_blocks_an_offline_claim(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-escalated-block-offline-1',
            'status' => 'pending',
        ]);
        app(MpesaInvestigationService::class)->escalateForManualVerification($transaction, null, 'No answer after repeated queries.');

        Livewire::actingAs($user)->test(PayRent::class)
            ->set('showOfflineForm', true)
            ->set('offline_method', 'cash')
            ->set('offline_amount', '4000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->call('submitOfflinePayment')
            ->assertHasErrors('offline_amount');

        $this->assertDatabaseMissing('payments', ['tenancy_id' => $tenancy->id, 'source' => 'tenant_submitted_offline']);
    }

    /** Final review, point 4: an escalated RENT transaction must never block an independent deposit attempt. */
    public function test_an_escalated_rent_transaction_does_not_block_a_deposit_attempt(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.callback_url' => 'https://example.test/mpesa/callback',
            'services.mpesa.callback_secret' => 'test-callback-secret',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => \Illuminate\Support\Facades\Http::response([
                'MerchantRequestID' => 'merchant-escalated-indep-1',
                'CheckoutRequestID' => 'checkout-escalated-indep-dep-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $rentTransaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-escalated-indep-rent-1',
            'status' => 'pending',
        ]);
        app(MpesaInvestigationService::class)->escalateForManualVerification($rentTransaction, null, 'No answer after repeated queries.');

        Livewire::actingAs($user)->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '10000')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertEquals(1, MpesaTransaction::where('tenancy_id', $tenancy->id)->where('purpose', 'deposit')->count());
    }

    /** Never trust the id a tenant supplies — requestCancellation() must re-scope to their own tenancy, exactly like Finding 1's ownership checks. */
    public function test_requestCancellation_cannot_touch_another_tenants_transaction(): void
    {
        $this->seed();
        $tenancies = Tenancy::active()->with('house.block.property')->get();
        $this->assertGreaterThan(1, $tenancies->count());
        [$victimTenancy, $attackerTenancy] = [$tenancies[0], $tenancies[1]];

        $attacker = User::factory()->create();
        $attacker->syncRoles(['tenant']);
        $attackerTenancy->tenant->update(['user_id' => $attacker->id]);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000099',
            'amount' => 9000,
            'checkout_request_id' => 'checkout-victim-cancel-1',
            'status' => 'pending',
        ]);
        $victimTransaction->forceFill(['created_at' => now()->subMinutes(15)])->save();

        Auth::login($attacker);
        $component = new PayRent();
        $component->requestCancellation($victimTransaction->id);

        $this->assertNull($victimTransaction->fresh()->cancellation_requested_at, 'An attacker must not be able to request cancellation on another tenant\'s transaction.');
    }
}
