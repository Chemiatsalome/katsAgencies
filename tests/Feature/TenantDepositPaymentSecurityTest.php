<?php

namespace Tests\Feature;

use App\Http\Controllers\MpesaCallbackController;
use App\Livewire\Tenant\PayDeposit;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mirrors TenantPaymentSecurityTest exactly, for PayDeposit instead of
 * PayRent — Finding 1 of the Phase 4 audit affected both components
 * identically (same unscoped MpesaTransaction::find() pattern in both
 * checkStatus() and render()). See that file's docblock for the full
 * explanation of why direct PHP instantiation (not Livewire's test
 * harness) is used for the "what if #[Locked] were bypassed" scenarios.
 */
class TenantDepositPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUserFor(Tenancy $tenancy): User
    {
        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $user->id]);

        return $user;
    }

    private function twoTenancies(): array
    {
        $this->seed();
        $tenancies = Tenancy::active()->with('house.block.property')->get();
        $this->assertGreaterThan(1, $tenancies->count(), 'Seed data must include at least two active tenancies.');

        return [$tenancies[0], $tenancies[1]];
    }

    private function bareComponent(int $pendingTransactionId): PayDeposit
    {
        $component = new PayDeposit();
        $component->pendingTransactionId = $pendingTransactionId;

        return $component;
    }

    public function test_pendingTransactionId_cannot_be_set_via_the_livewire_update_mechanism(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000011',
            'amount' => 23456,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-dep-victim-1',
            'status' => 'pending',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/[Ll]ocked/');

        Livewire::actingAs($attacker)
            ->test(PayDeposit::class)
            ->set('pendingTransactionId', $victimTransaction->id);
    }

    public function test_render_does_not_disclose_another_tenants_deposit_transaction(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000012',
            'amount' => 87654,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-dep-victim-2',
            'status' => 'success',
            'mpesa_receipt_number' => 'DEP-VICTIM-RECEIPT-2',
        ]);

        Auth::login($attacker);
        $component = $this->bareComponent($victimTransaction->id);

        $view = $component->render();
        $this->assertNull($view->getData()['transaction'], 'A foreign deposit transaction id must resolve to null, never the real row.');
    }

    public function test_checkStatus_cannot_timeout_another_tenants_deposit_transaction(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000013',
            'amount' => 4000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-dep-victim-3',
            'status' => 'pending',
        ]);
        $victimTransaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Auth::login($attacker);
        $component = $this->bareComponent($victimTransaction->id);
        $component->checkStatus();

        $this->assertEquals('pending', $victimTransaction->fresh()->status, 'The victim deposit transaction must be untouched.');
    }

    public function test_a_forged_pendingTransactionId_is_safely_reset_on_pay_deposit(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000014',
            'amount' => 6000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-dep-victim-4',
            'status' => 'pending',
        ]);

        Auth::login($attacker);

        $component = $this->bareComponent($victimTransaction->id);
        $component->checkStatus();
        $this->assertNull($component->pendingTransactionId);

        $component2 = $this->bareComponent(999999);
        $component2->checkStatus();
        $this->assertNull($component2->pendingTransactionId);
    }

    public function test_victims_deposit_transaction_remains_eligible_for_a_genuine_success_callback_after_an_attack_attempt(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);
        $victimUser = $this->makeTenantUserFor($victimTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimUser->id,
            'phone' => '254700000015',
            'amount' => 5000,
            'purpose' => 'deposit',
            'checkout_request_id' => 'checkout-dep-victim-5',
            'status' => 'pending',
        ]);
        $victimTransaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Auth::login($attacker);
        $attackComponent = $this->bareComponent($victimTransaction->id);
        $attackComponent->checkStatus();
        $this->assertEquals('pending', $victimTransaction->fresh()->status);

        app(MpesaCallbackController::class)->processResult(
            'checkout-dep-victim-5',
            0,
            'The service request is processed successfully.',
            [
                'CallbackMetadata' => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 5000],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'DEP-VICTIM-REAL-RECEIPT'],
                        ['Name' => 'TransactionDate', 'Value' => now()->format('YmdHis')],
                    ],
                ],
            ],
        );

        $victimTransaction->refresh();
        $this->assertEquals('success', $victimTransaction->status);
        $this->assertEquals('DEP-VICTIM-REAL-RECEIPT', $victimTransaction->mpesa_receipt_number);
        $this->assertNotNull($victimTransaction->deposit_payment_id);
    }

    public function test_the_legitimate_tenant_can_still_poll_and_view_their_own_deposit_transaction(): void
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
                'MerchantRequestID' => 'merchant-dep-owner-1',
                'CheckoutRequestID' => 'checkout-dep-owner-1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);

        [$ownTenancy] = $this->twoTenancies();
        $owner = $this->makeTenantUserFor($ownTenancy);

        $component = Livewire::actingAs($owner)
            ->test(PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasNoErrors();

        $transaction = MpesaTransaction::where('tenancy_id', $ownTenancy->id)->firstOrFail();
        $transaction->update(['status' => 'success', 'mpesa_receipt_number' => 'DEP-OWNER-RECEIPT-1']);

        $component
            ->call('checkStatus')
            ->assertSee('5,000.00')
            ->assertSee('DEP-OWNER-RECEIPT-1');
    }
}
