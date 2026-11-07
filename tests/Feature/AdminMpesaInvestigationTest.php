<?php

namespace Tests\Feature;

use App\Livewire\Admin\Payments\Index;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 audit, Finding 4: the admin-facing side of Admin\Payments\Index —
 * previously fully read-only, now also able to re-query Safaricom and
 * record an evidence-based resolution through MpesaInvestigationService.
 * The service's own authorisation/locking/audit-trail guarantees are
 * covered directly in MpesaInvestigationServiceTest; this file covers the
 * Livewire wiring (route access, the resolve-dialog flow, validation).
 */
class AdminMpesaInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        return $admin;
    }

    public function test_a_non_admin_cannot_reach_the_payments_page_at_all(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $this->actingAs($landlord)->get(route('admin.payments.index'))->assertForbidden();
    }

    public function test_admin_can_mark_a_stuck_transaction_failed_with_a_reason(): void
    {
        $this->seed();
        $admin = $this->admin();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-admin-ui-fail-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('startResolve', $transaction->id, 'failed')
            ->set('resolveReason', 'Tenant confirmed by phone they declined the PIN prompt.')
            ->call('confirmResolve')
            ->assertHasNoErrors();

        $this->assertEquals('failed', $transaction->fresh()->status);
        $this->assertEquals($admin->id, $transaction->fresh()->investigated_by);
    }

    public function test_confirmResolve_requires_a_reason(): void
    {
        $this->seed();
        $admin = $this->admin();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-admin-ui-noreason-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('startResolve', $transaction->id, 'failed')
            ->set('resolveReason', '')
            ->call('confirmResolve')
            ->assertHasErrors('resolveReason');

        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    public function test_resolve_as_successful_requires_a_reference_and_rejects_an_unmatched_one(): void
    {
        $this->seed();
        $admin = $this->admin();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-admin-ui-success-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('startResolve', $transaction->id, 'successful')
            ->set('resolveReason', 'Found on statement.')
            ->set('resolveReference', '')
            ->call('confirmResolve')
            ->assertHasErrors('resolveReference');

        Livewire::actingAs($admin)->test(Index::class)
            ->call('startResolve', $transaction->id, 'successful')
            ->set('resolveReason', 'Found on statement.')
            ->set('resolveReference', 'NOT-RECORDED-ANYWHERE')
            ->call('confirmResolve')
            ->assertHasErrors('resolveReason');

        $this->assertEquals('pending', $transaction->fresh()->status);
    }

    public function test_resolve_as_successful_succeeds_once_a_matching_confirmed_payment_exists(): void
    {
        $this->seed();
        $admin = $this->admin();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 4000, 'amount_paid' => 0, 'status' => 'unpaid',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id,
            'amount' => 4000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'reference_number' => 'MATCHED-REF-1', 'recorded_by' => $admin->id, 'status' => 'confirmed',
        ]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-admin-ui-success-2',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('startResolve', $transaction->id, 'successful')
            ->set('resolveReason', 'Found on statement.')
            ->set('resolveReference', 'MATCHED-REF-1')
            ->call('confirmResolve')
            ->assertHasNoErrors();

        $this->assertEquals('success', $transaction->fresh()->status);
    }

    public function test_admin_requery_records_history_and_shows_a_status_message(): void
    {
        config([
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
        ]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-admin-ui-requery-1',
                'ResultCode' => 1032,
                'ResultDesc' => 'Request cancelled by user',
            ]),
        ]);

        $this->seed();
        $admin = $this->admin();
        $tenancy = Tenancy::active()->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 4000,
            'checkout_request_id' => 'checkout-admin-ui-requery-1',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('requery', $transaction->id);

        $this->assertEquals('cancelled', $transaction->fresh()->status);
        $this->assertEquals(1, \App\Models\MpesaStatusQuery::where('mpesa_transaction_id', $transaction->id)->where('source', 'admin_requery')->count());
    }

    /** The action methods must never be reachable for an sms_topup row, which has no tenancy-side resolution flow. */
    public function test_resolution_actions_are_not_offered_for_sms_topup_rows(): void
    {
        $this->seed();
        $admin = $this->admin();
        $landlord = User::role('landlord')->firstOrFail();

        MpesaTransaction::create([
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 500,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-admin-ui-sms-1',
            'status' => 'pending',
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        $this->assertStringNotContainsString('Mark successful', $html);
    }
}
