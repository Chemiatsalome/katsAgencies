<?php

namespace Tests\Feature;

use App\Http\Controllers\MpesaCallbackController;
use App\Livewire\Tenant\PayRent;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 1 (Critical): $pendingTransactionId was a plain
 * public Livewire property with no protection and no ownership check —
 * checkStatus() and render() both did a bare MpesaTransaction::find() on
 * whatever value it held. A tenant could set it to another tenant's
 * (sequential, trivially guessable) transaction id to read that
 * transaction's details, or — far worse — force a still-genuinely-pending
 * transaction into 'timeout', which MpesaCallbackController::processResult()
 * then treats as already-finished, silently discarding the real Safaricom
 * success callback that arrives afterward. The victim is charged and never
 * credited.
 *
 * Fixed with two independent layers:
 *  1. #[Locked] rejects any client-sent update to the property outright
 *     (test_pendingTransactionId_cannot_be_set_via_the_livewire_update_mechanism
 *     exercises this through the real Livewire update pipeline).
 *  2. ownPendingTransaction() re-scopes every lookup to the authenticated
 *     tenant's own tenancy regardless of what the property holds — "never
 *     rely on the property alone". The remaining tests exercise this layer
 *     by calling the component's methods directly on a bare PHP instance,
 *     bypassing Livewire's test harness entirely: Testable::call() re-
 *     hydrates a component from its stored snapshot on every call, so a
 *     direct ->instance()->pendingTransactionId mutation never actually
 *     reaches a subsequent ->call() in that harness — it would silently
 *     test nothing. Driving the component directly as a plain object is
 *     the only way to prove layer 2 holds independently of layer 1.
 */
class TenantPaymentSecurityTest extends TestCase
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

    /** A bare component instance, exactly as PHP would construct one — no Livewire snapshot/hydration involved. */
    private function bareComponent(int $pendingTransactionId): PayRent
    {
        $component = new PayRent();
        $component->pendingTransactionId = $pendingTransactionId;

        return $component;
    }

    /**
     * Layer 1: the client-facing update path is rejected outright. This is
     * the normal, expected way the vulnerability is closed — a real
     * attacker never gets further than this.
     */
    public function test_pendingTransactionId_cannot_be_set_via_the_livewire_update_mechanism(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000001',
            'amount' => 12345,
            'checkout_request_id' => 'checkout-victim-1',
            'status' => 'pending',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/[Ll]ocked/');

        Livewire::actingAs($attacker)
            ->test(PayRent::class)
            ->set('pendingTransactionId', $victimTransaction->id);
    }

    /**
     * Layer 2, scenario 1 of the requested 5: even if a foreign id were
     * somehow present in the property, render() must not disclose another
     * tenant's transaction (amount, phone, receipt).
     */
    public function test_render_does_not_disclose_another_tenants_transaction(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000002',
            'amount' => 98765,
            'checkout_request_id' => 'checkout-victim-2',
            'status' => 'success',
            'mpesa_receipt_number' => 'VICTIM-RECEIPT-2',
        ]);

        Auth::login($attacker);
        $component = $this->bareComponent($victimTransaction->id);

        // Checking the view data directly (rather than the rendered HTML)
        // is the reliable way to test this outside Livewire's own render
        // pipeline — that pipeline normally injects every public component
        // property into the view as a variable automatically (phone,
        // amount, paymentOption, ...), which only happens when Livewire
        // itself drives render(), not on a bare PHP call. The security
        // property that matters is already fully proven here: 'transaction'
        // is null, so the blade can never print the victim's data at all.
        $view = $component->render();
        $this->assertNull($view->getData()['transaction'], 'A foreign transaction id must resolve to null, never the real row.');
    }

    /** Scenario 2: checkStatus() must not be able to mutate another tenant's transaction. */
    public function test_checkStatus_cannot_timeout_another_tenants_transaction(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000003',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-victim-3',
            'status' => 'pending',
        ]);
        // Old enough that checkStatus() would time it out if it ever reached it.
        $victimTransaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Auth::login($attacker);
        $component = $this->bareComponent($victimTransaction->id);
        $component->checkStatus();

        $this->assertEquals('pending', $victimTransaction->fresh()->status, 'The victim transaction must be untouched.');
    }

    /**
     * Scenario 3: a forged/mismatched id must be safely reset, not left
     * dangling — the attacker's own component falls back to showing
     * nothing is pending, not an error state.
     */
    public function test_a_forged_pendingTransactionId_is_safely_reset(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimTenancy->tenant->user_id ?? $attacker->id,
            'phone' => '254700000004',
            'amount' => 7000,
            'checkout_request_id' => 'checkout-victim-4',
            'status' => 'pending',
        ]);

        Auth::login($attacker);

        $component = $this->bareComponent($victimTransaction->id);
        $component->checkStatus();
        $this->assertNull($component->pendingTransactionId);

        // A completely non-existent id is handled identically.
        $component2 = $this->bareComponent(999999);
        $component2->checkStatus();
        $this->assertNull($component2->pendingTransactionId);
    }

    /**
     * Scenario 4: the fix must not create a new problem of its own — the
     * victim's transaction, left untouched by the attack, remains fully
     * eligible to be credited by the real Safaricom callback that
     * eventually arrives.
     */
    public function test_victims_transaction_remains_eligible_for_a_genuine_success_callback_after_an_attack_attempt(): void
    {
        [$victimTenancy, $attackerTenancy] = $this->twoTenancies();
        $attacker = $this->makeTenantUserFor($attackerTenancy);
        $victimUser = $this->makeTenantUserFor($victimTenancy);

        $victimTransaction = MpesaTransaction::create([
            'tenancy_id' => $victimTenancy->id,
            'initiated_by' => $victimUser->id,
            'phone' => '254700000005',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-victim-5',
            'status' => 'pending',
        ]);
        $victimTransaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        // The attack attempt — must have no effect (already proven above,
        // re-exercised here as part of the same end-to-end story).
        Auth::login($attacker);
        $attackComponent = $this->bareComponent($victimTransaction->id);
        $attackComponent->checkStatus();
        $this->assertEquals('pending', $victimTransaction->fresh()->status);

        // The genuine Safaricom callback for the victim's own real payment.
        app(MpesaCallbackController::class)->processResult(
            'checkout-victim-5',
            0,
            'The service request is processed successfully.',
            [
                'CallbackMetadata' => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 5000],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'VICTIM-REAL-RECEIPT'],
                        ['Name' => 'TransactionDate', 'Value' => now()->format('YmdHis')],
                    ],
                ],
            ],
        );

        $victimTransaction->refresh();
        $this->assertEquals('success', $victimTransaction->status);
        $this->assertEquals('VICTIM-REAL-RECEIPT', $victimTransaction->mpesa_receipt_number);
        $this->assertNotNull($victimTransaction->payment_id);
    }

    /**
     * Scenario 5: none of this gets in the way of the legitimate tenant
     * polling and viewing their own transaction — driven through the real
     * Livewire flow end to end (pay() sets pendingTransactionId server-side,
     * exactly as a real tenant's browser would trigger it).
     */
    public function test_the_legitimate_tenant_can_still_poll_and_view_their_own_transaction(): void
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
                'MerchantRequestID' => 'merchant-owner-1',
                'CheckoutRequestID' => 'checkout-owner-1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);

        [$ownTenancy] = $this->twoTenancies();
        $owner = $this->makeTenantUserFor($ownTenancy);

        $component = Livewire::actingAs($owner)
            ->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasNoErrors();

        $transaction = MpesaTransaction::where('tenancy_id', $ownTenancy->id)->firstOrFail();
        $transaction->update(['status' => 'success', 'mpesa_receipt_number' => 'OWNER-RECEIPT-1']);

        $component
            ->call('checkStatus')
            ->assertSee('5,000.00')
            ->assertSee('OWNER-RECEIPT-1');
    }
}
