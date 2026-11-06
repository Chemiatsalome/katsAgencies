<?php

namespace Tests\Feature;

use App\Livewire\Tenant\PayDeposit;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_flagged_deposit_transaction_shows_needs_verification_not_payment_failed(): void
    {
        $this->seed();
        $tenantUser = $this->makeTenantUser();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $tenantUser->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-deposit-flagged-ui-1',
            'status' => 'failed',
            'result_code' => 0,
            'result_desc' => 'Receipt already recorded (likely entered manually) — flagged, not credited again.',
        ]);

        Livewire::actingAs($tenantUser)
            ->test(PayDeposit::class)
            ->set('pendingTransactionId', $transaction->id)
            ->assertSee('Needs verification')
            ->assertSee('pay again')
            ->assertDontSee('Payment failed')
            ->assertDontSee('Try Again');
    }

    public function test_a_genuinely_failed_deposit_transaction_still_shows_payment_failed_with_retry(): void
    {
        $this->seed();
        $tenantUser = $this->makeTenantUser();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $tenantUser->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-deposit-genuine-fail-ui-1',
            'status' => 'failed',
            'result_code' => 1,
            'result_desc' => "The balance on the customer's account is insufficient.",
        ]);

        Livewire::actingAs($tenantUser)
            ->test(PayDeposit::class)
            ->set('pendingTransactionId', $transaction->id)
            ->assertSee('Payment failed')
            ->assertSee('Try Again')
            ->assertDontSee('Needs verification');
    }
}
