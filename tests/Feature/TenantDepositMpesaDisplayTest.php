<?php

namespace Tests\Feature;

use App\Livewire\Tenant\PayDeposit;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deposit payments share PayRent's exact "flagged by the callback, not a
 * plain decline" display bug (see TenantMpesaPaymentTest) — same fix,
 * mirrored here since no prior PayDeposit coverage existed at all.
 */
class TenantDepositMpesaDisplayTest extends TestCase
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

    private function fakeStkPush(string $checkoutRequestId): void
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
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-'.$checkoutRequestId,
                'CheckoutRequestID' => $checkoutRequestId,
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);
    }

    /**
     * pendingTransactionId is #[Locked] (see Phase 4 audit Finding 1) — it
     * can no longer be injected directly via Livewire's test ->set().
     * Driving the real pay() flow instead, then mutating the resulting
     * transaction to the flagged state, exercises the same render() path
     * without relying on the now-blocked shortcut.
     */
    public function test_a_flagged_deposit_transaction_shows_needs_verification_not_payment_failed(): void
    {
        $this->fakeStkPush('checkout-deposit-flagged-ui-1');
        $this->seed();
        $tenantUser = $this->makeTenantUser();
        $tenancy = Tenancy::active()->firstOrFail();

        $component = Livewire::actingAs($tenantUser)
            ->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay');

        $transaction = MpesaTransaction::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('deposit', $transaction->purpose);
        $transaction->update([
            'status' => 'failed',
            'result_code' => 0,
            'result_desc' => 'Receipt already recorded (likely entered manually) — flagged, not credited again.',
        ]);

        $component
            ->call('checkStatus')
            ->assertSee('Needs verification')
            ->assertSee('pay again')
            ->assertDontSee('Payment failed')
            ->assertDontSee('Try Again');
    }

    public function test_a_genuinely_failed_deposit_transaction_still_shows_payment_failed_with_retry(): void
    {
        $this->fakeStkPush('checkout-deposit-genuine-fail-ui-1');
        $this->seed();
        $tenantUser = $this->makeTenantUser();
        $tenancy = Tenancy::active()->firstOrFail();

        $component = Livewire::actingAs($tenantUser)
            ->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay');

        $transaction = MpesaTransaction::where('tenancy_id', $tenancy->id)->firstOrFail();
        $transaction->update([
            'status' => 'failed',
            'result_code' => 1,
            'result_desc' => "The balance on the customer's account is insufficient.",
        ]);

        $component
            ->call('checkStatus')
            ->assertSee('Payment failed')
            ->assertSee('Try Again')
            ->assertDontSee('Needs verification');
    }

    /** Phase 4 audit, Finding 3 (Medium) — same guard added to PayDeposit's offline form, verified the same way as PayRent's. */
    public function test_submit_for_confirmation_button_carries_a_loading_guard_scoped_to_itself(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();

        $html = Livewire::actingAs($user)
            ->test(PayDeposit::class)
            ->call('openOfflineForm')
            ->html();

        $this->assertStringContainsString('wire:loading.attr="disabled" wire:target="submitOfflinePayment"', $html);
        $this->assertStringContainsString('Submit for Confirmation', $html);

        $cancelButtonTag = (string) preg_replace('/.*(<button type="button" wire:click="cancelOfflineForm"[^>]*>).*/s', '$1', $html);
        $this->assertStringNotContainsString('wire:loading', $cancelButtonTag);
    }
}
