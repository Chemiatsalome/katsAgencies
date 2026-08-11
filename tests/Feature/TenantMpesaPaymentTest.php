<?php

namespace Tests\Feature;

use App\Livewire\Tenant\PayRent;
use App\Models\AppNotification;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TenantMpesaPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_tenant_can_initiate_an_stk_push_and_it_records_a_pending_transaction(): void
    {
        config(['services.mpesa.consumer_key' => 'test-key', 'services.mpesa.consumer_secret' => 'test-secret', 'services.mpesa.callback_url' => 'https://example.test/mpesa/callback']);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-123',
                'CheckoutRequestID' => 'checkout-abc',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);

        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Livewire::actingAs($user)
            ->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasNoErrors();

        $transaction = MpesaTransaction::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('pending', $transaction->status);
        $this->assertEquals('checkout-abc', $transaction->checkout_request_id);
        $this->assertEquals('254712345678', $transaction->phone);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'stkpush') && $request['PhoneNumber'] === '254712345678';
        });
    }

    public function test_missing_mpesa_credentials_shows_a_friendly_error_instead_of_crashing(): void
    {
        config(['services.mpesa.consumer_key' => null, 'services.mpesa.consumer_secret' => null]);

        $this->seed();
        $user = $this->makeTenantUser();

        Livewire::actingAs($user)
            ->test(PayRent::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasErrors('phone');

        $this->assertDatabaseCount('mpesa_transactions', 1); // created before the OAuth call fails
    }

    public function test_successful_callback_creates_a_payment_and_notifies_tenant_and_landlord(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property', 'tenant')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;
        $tenantUser = User::factory()->create();
        $tenantUser->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $tenantUser->id]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $tenantUser->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-success-1',
            'status' => 'pending',
        ]);

        $callbackPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-1',
                    'CheckoutRequestID' => 'checkout-success-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 5000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'TransactionDate', 'Value' => 20260810143000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson(route('mpesa.callback'), $callbackPayload);
        $response->assertOk();
        $response->assertJson(['ResultCode' => 0]);

        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('NLJ7RT61SV', $transaction->mpesa_receipt_number);
        $this->assertNotNull($transaction->payment_id);

        $this->assertDatabaseHas('payments', [
            'tenancy_id' => $tenancy->id,
            'reference_number' => 'NLJ7RT61SV',
            'method' => 'mpesa',
        ]);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'payment_confirmed')
                ->exists()
        );

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'tenant_paid_mpesa')
                ->exists()
        );
    }

    public function test_cancelled_callback_does_not_create_a_payment(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->firstOrFail();
        $user = User::factory()->create();
        $user->syncRoles(['tenant']);

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $user->id,
            'phone' => '254712345678',
            'amount' => 5000,
            'checkout_request_id' => 'checkout-cancel-1',
            'status' => 'pending',
        ]);

        $response = $this->postJson(route('mpesa.callback'), [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-2',
                    'CheckoutRequestID' => 'checkout-cancel-1',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ]);

        $response->assertOk();

        $transaction = MpesaTransaction::where('checkout_request_id', 'checkout-cancel-1')->firstOrFail();
        $this->assertEquals('cancelled', $transaction->status);
        $this->assertNull($transaction->payment_id);
        $this->assertDatabaseMissing('payments', ['tenancy_id' => $tenancy->id, 'method' => 'mpesa']);
    }

    public function test_payment_option_toggles_between_full_balance_and_one_month(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        // Two more overdue months on top of whatever the seeder already generated,
        // so "full balance" and "this month" are guaranteed to differ.
        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonths(2)->year,
            'period_month' => now()->subMonths(2)->month,
            'due_date' => now()->subMonths(2),
            'amount_expected' => $tenancy->monthly_rent,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);
        RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonths(1)->year,
            'period_month' => now()->subMonths(1)->month,
            'due_date' => now()->subMonths(1),
            'amount_expected' => $tenancy->monthly_rent,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);

        $tenancy->refresh();
        $fullBalance = $tenancy->balance();
        $monthAmount = (float) $tenancy->rentCharges()->outstanding()->orderBy('due_date')->first()->amount_expected;
        $this->assertGreaterThan($monthAmount, $fullBalance, 'Full balance should exceed a single month once arrears exist.');

        $component = Livewire::actingAs($user)->test(PayRent::class);

        $component->assertSet('paymentOption', 'full')
            ->assertSet('amount', number_format($fullBalance, 2, '.', ''));

        $component->set('paymentOption', 'month')
            ->assertSet('amount', number_format($monthAmount, 2, '.', ''));

        $component->set('paymentOption', 'custom')
            ->set('amount', '1234.00')
            ->assertSet('amount', '1234.00');
    }

    public function test_a_lump_sum_covering_two_months_splits_across_both_rent_charges_oldest_first(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property', 'tenant')->firstOrFail();
        $tenantUser = User::factory()->create();
        $tenantUser->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $tenantUser->id]);

        RentCharge::where('tenancy_id', $tenancy->id)->delete();

        $older = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->subMonth()->year,
            'period_month' => now()->subMonth()->month,
            'due_date' => now()->subMonth(),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'overdue',
        ]);
        $newer = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now(),
            'amount_expected' => 10000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => $tenantUser->id,
            'phone' => '254712345678',
            'amount' => 15000, // covers the older charge fully (10k) + half the newer one (5k)
            'checkout_request_id' => 'checkout-split-1',
            'status' => 'pending',
        ]);

        $response = $this->postJson(route('mpesa.callback'), [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'checkout-split-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 15000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SPLIT001'],
                            ['Name' => 'TransactionDate', 'Value' => 20260810143000],
                        ],
                    ],
                ],
            ],
        ]);
        $response->assertOk();

        $older->refresh();
        $newer->refresh();

        $this->assertEquals('paid', $older->status);
        $this->assertEquals(10000, (float) $older->amount_paid);

        $this->assertEquals('partial', $newer->status);
        $this->assertEquals(5000, (float) $newer->amount_paid);

        $this->assertEquals(2, Payment::where('reference_number', 'SPLIT001')->count());
        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $this->assertNotNull($transaction->payment_id);
    }

    public function test_callback_route_is_exempt_from_csrf_protection(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => 1000,
            'checkout_request_id' => 'checkout-csrf-check',
            'status' => 'pending',
        ]);

        // A plain POST with no _token / X-CSRF-TOKEN must still succeed.
        $response = $this->post(route('mpesa.callback'), [
            'Body' => ['stkCallback' => ['CheckoutRequestID' => 'checkout-csrf-check', 'ResultCode' => 1, 'ResultDesc' => 'Failed']],
        ]);

        $response->assertOk();
    }
}
