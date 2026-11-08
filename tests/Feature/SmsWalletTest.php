<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileStuckMpesaTransactions;
use App\Livewire\Admin\Payments\Index as AdminPaymentsIndex;
use App\Livewire\Admin\Settings\Index as AdminSettingsIndex;
use App\Livewire\Admin\SmsWallets\Index as AdminSmsWalletsIndex;
use App\Livewire\Landlord\SmsWallet as SmsWalletPage;
use App\Models\AppNotification;
use App\Models\Block;
use App\Models\House;
use App\Models\Manager;
use App\Models\MpesaTransaction;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\SmsWalletEntry;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use App\Services\SmsWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SMS rent reminders cost Kats real money via Africa's Talking — this is
 * the opt-in prepaid billing layer: a landlord tops up their own wallet via
 * Kats' platform M-Pesa paybill (never their own shortcode, even if they've
 * set up Direct Collection), at a single admin-set platform-wide rate, and
 * SMS reminders silently fall back to in-app/email once the balance runs
 * dry — see SmsWalletService and MpesaCallbackController's sms_topup branch.
 */
class SmsWalletTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK_TOKEN = 'test-callback-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mpesa.callback_secret' => self::CALLBACK_TOKEN,
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.callback_url' => 'https://example.test/mpesa/callback',
        ]);
    }

    private function callbackUrl(): string
    {
        return route('mpesa.callback', ['token' => self::CALLBACK_TOKEN]);
    }

    public function test_balance_nets_credits_against_debits(): void
    {
        $landlord = User::factory()->create();

        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'credit', 'amount' => 500]);
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'debit', 'amount' => 30]);
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'debit', 'amount' => 20]);

        $this->assertEquals(450.0, app(SmsWalletService::class)->balance($landlord->id));
    }

    public function test_debit_refuses_when_balance_is_insufficient(): void
    {
        $landlord = User::factory()->create();
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'credit', 'amount' => 5]);

        $ok = app(SmsWalletService::class)->debit($landlord->id, 10);

        $this->assertFalse($ok);
        $this->assertEquals(5.0, app(SmsWalletService::class)->balance($landlord->id));
        $this->assertDatabaseCount('sms_wallet_entries', 1);
    }

    public function test_initiate_topup_always_uses_the_platform_shortcode_even_for_a_direct_collection_landlord(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-sms-1',
                'CheckoutRequestID' => 'checkout-sms-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        // Even a landlord who has set up their own Direct Collection
        // shortcode must have an SMS top-up settle into Kats' own platform
        // shortcode — they're paying Kats, never themselves.
        \App\Models\LandlordSetting::set($landlord->id, 'payout_shortcode', '999999');
        \App\Models\LandlordSetting::setEncrypted($landlord->id, 'mpesa_passkey', 'landlord-own-passkey');

        app(SmsWalletService::class)->initiateTopUp($landlord->id, $landlord->id, '0712345678', 100);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'stkpush')
            && $request['BusinessShortCode'] === '174379' // Kats' platform shortcode, not the landlord's own '999999'
        );

        $transaction = MpesaTransaction::where('checkout_request_id', 'checkout-sms-1')->firstOrFail();
        $this->assertEquals('sms_topup', $transaction->purpose);
        $this->assertEquals($landlord->id, $transaction->landlord_id);
        $this->assertNull($transaction->tenancy_id);
        $this->assertEquals('pending', $transaction->status);
    }

    /**
     * Real-world bug: the Payments settings page shows the composed
     * callback URL (base + secret) as a copy-paste reference, and an admin
     * pasted that reference back into the base Callback URL field. Every
     * push then sent a CallBackURL with the secret appended twice
     * (.../mpesa/callback/{secret}/{secret}), which has no matching route
     * — every callback 404'd. callbackUrl() now strips a pre-existing
     * trailing secret before appending it, so a bad save degrades
     * gracefully instead of breaking every callback silently.
     */
    public function test_a_callback_url_that_already_has_the_secret_appended_does_not_get_it_doubled(): void
    {
        \App\Models\Setting::set('mpesa_callback_url', 'https://example.test/mpesa/callback/the-secret-token');
        \App\Models\Setting::setEncrypted('mpesa_callback_secret', 'the-secret-token');

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-sms-dedupe-1',
                'CheckoutRequestID' => 'checkout-sms-dedupe-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $landlord = User::factory()->create();

        app(SmsWalletService::class)->initiateTopUp($landlord->id, $landlord->id, '0712345678', 100);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'stkpush')
            && $request['CallBackURL'] === 'https://example.test/mpesa/callback/the-secret-token'
        );
    }

    public function test_successful_sms_topup_callback_credits_the_wallet_and_notifies_the_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => null,
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 500,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-sms-topup-1',
            'status' => 'pending',
        ]);

        $callbackPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-sms-topup-1',
                    'CheckoutRequestID' => 'checkout-sms-topup-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SMSWLT123'],
                            ['Name' => 'TransactionDate', 'Value' => 20260811090000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson($this->callbackUrl(), $callbackPayload);
        $response->assertOk();

        $this->assertEquals(500.0, app(SmsWalletService::class)->balance($landlord->id));

        $this->assertDatabaseHas('sms_wallet_entries', [
            'landlord_id' => $landlord->id,
            'type' => 'credit',
            'amount' => 500,
            'mpesa_transaction_id' => $transaction->id,
        ]);

        // This callback must never touch the rent/deposit tables.
        $this->assertDatabaseMissing('payments', ['mpesa_transaction_id' => $transaction->id]);
        $this->assertDatabaseMissing('deposit_payments', ['reference_number' => 'SMSWLT123']);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'sms_wallet_topped_up')
                ->exists()
        );
    }

    public function test_failed_sms_topup_callback_notifies_the_landlord_not_a_tenant(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => null,
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 200,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-sms-topup-failed-1',
            'status' => 'pending',
        ]);

        $callbackPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-sms-topup-failed-1',
                    'CheckoutRequestID' => 'checkout-sms-topup-failed-1',
                    'ResultCode' => 1,
                    'ResultDesc' => 'Insufficient funds.',
                ],
            ],
        ];

        $this->postJson($this->callbackUrl(), $callbackPayload)->assertOk();

        $this->assertEquals(0.0, app(SmsWalletService::class)->balance($landlord->id));

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'sms_wallet_topup_failed')
                ->exists()
        );
    }

    public function test_sms_reminder_debits_the_wallet_only_after_a_successful_send(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property', 'tenant')->firstOrFail();
        $landlordId = $tenancy->house->block->property->landlord_id;
        $tenancy->tenant->update(['phone' => '0712345678']);

        SmsWalletEntry::create(['landlord_id' => $landlordId, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');

        config(['services.africastalking.api_key' => 'fake-at-key']);
        Http::fake([
            '*africastalking.com*' => Http::response([
                'SMSMessageData' => ['Recipients' => [['status' => 'Success']]],
            ]),
        ]);

        app(NotificationService::class)->notify(
            $tenancy->tenant, 'rent_due_soon_test', 'Rent Payment Reminder', 'Test reminder body', ['sms'], $tenancy,
        );

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($landlordId));
        $this->assertDatabaseHas('sms_wallet_entries', ['landlord_id' => $landlordId, 'type' => 'debit', 'amount' => 2]);

        $notification = AppNotification::where('channel', 'sms')->latest()->first();
        $this->assertEquals('sent', $notification->status);
    }

    public function test_sms_reminder_falls_back_silently_when_the_wallet_is_empty_but_other_channels_still_send(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property', 'tenant')->firstOrFail();
        $tenancy->tenant->update(['phone' => '0712345678']);

        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        // No wallet entries at all — balance is 0, below the rate.

        Http::fake([
            '*africastalking.com*' => Http::response([
                'SMSMessageData' => ['Recipients' => [['status' => 'Success']]],
            ]),
        ]);

        app(NotificationService::class)->notify(
            $tenancy->tenant, 'rent_due_soon_test', 'Rent Payment Reminder', 'Test reminder body', ['sms', 'in_app'], $tenancy,
        );

        // Never even attempted the send — the cheap balance pre-check short-circuits it.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking.com'));

        $smsNotification = AppNotification::where('channel', 'sms')->latest()->first();
        $this->assertEquals('failed', $smsNotification->status);
        $this->assertDatabaseCount('sms_wallet_entries', 0);

        // The other channel in the same notify() call is a separate row,
        // unaffected by the SMS wallet being empty.
        $inAppNotification = AppNotification::where('channel', 'in_app')->latest()->first();
        $this->assertEquals('sent', $inAppNotification->status);
    }

    /**
     * Real-world bug: a stuck sms_topup (no callback ever received — e.g. a
     * dead ngrok tunnel) was previously an unfixable dead end, since
     * queryStkStatus() derived the landlord via a tenancy that an sms_topup
     * transaction doesn't have, returning null before ever asking
     * Safaricom. It now resolves it through the exact same processResult()
     * path a real callback would take.
     */
    public function test_reconciliation_resolves_a_stuck_sms_topup_with_no_callback_received(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $transaction = MpesaTransaction::create([
            'tenancy_id' => null,
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 50,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-sms-stuck-1',
            'status' => 'pending',
        ]);
        // created_at isn't mass-assignable (see MpesaTransaction's
        // #[Fillable]) — forceFill it directly so this row is old enough
        // for the command's "pending for 2+ minutes" query to pick up.
        $transaction->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-sms-stuck-1',
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $this->artisan(ReconcileStuckMpesaTransactions::class)->assertSuccessful();

        $transaction->refresh();
        $this->assertEquals('success', $transaction->status);
        $this->assertEquals(50.0, app(SmsWalletService::class)->balance($landlord->id));
        $this->assertDatabaseHas('sms_wallet_entries', [
            'landlord_id' => $landlord->id,
            'type' => 'credit',
            'amount' => 50,
            'mpesa_transaction_id' => $transaction->id,
        ]);
    }

    public function test_admin_can_set_the_platform_wide_sms_rate(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(AdminSettingsIndex::class)
            ->set('tab', 'payments')
            ->set('mpesa_env', 'sandbox')
            ->set('mpesa_consumer_key', 'k')
            ->set('mpesa_shortcode', '174379')
            ->set('mpesa_callback_url', 'https://kats.example.test/mpesa/callback')
            ->set('mpesa_callback_secret', str_repeat('a', 40))
            ->set('sms_rate_per_message', '1.50')
            ->call('savePayments')
            ->assertHasNoErrors();

        $this->assertEquals('1.50', \App\Models\Setting::get('sms_rate_per_message'));
        $this->assertEquals(1.50, app(SmsWalletService::class)->rate());
    }

    public function test_landlord_can_initiate_a_wallet_topup_from_the_sms_wallet_page(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-sms-page-1',
                'CheckoutRequestID' => 'checkout-sms-page-1',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SmsWalletPage::class)
            ->set('phone', '0712345678')
            ->set('amount', '100')
            ->call('topUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mpesa_transactions', [
            'purpose' => 'sms_topup',
            'landlord_id' => $landlord->id,
            'tenancy_id' => null,
            'checkout_request_id' => 'checkout-sms-page-1',
        ]);
    }

    public function test_admin_sms_wallets_page_shows_correct_balance_and_totals_per_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();

        // approvedLandlords() (what the Index page lists) requires
        // approved_at — the seeded demo landlord only has this backfilled
        // in the persistent dev DB's migration history, not under a fresh
        // RefreshDatabase test run.
        $landlord->forceFill(['approved_at' => now()])->save();

        \App\Models\LandlordSetting::set($landlord->id, 'reminder_channels', 'in_app,sms');
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'credit', 'amount' => 200]);
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'debit', 'amount' => 30]);
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'debit', 'amount' => 20]);

        Livewire::actingAs($admin)
            ->test(AdminSmsWalletsIndex::class)
            ->assertSee($landlord->name)
            ->assertSee('150.00') // balance: 200 - 30 - 20
            ->assertSee('200.00'); // total topped up
    }

    public function test_admin_incoming_payments_page_shows_the_landlord_for_an_sms_topup_row_and_filters_by_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $admin = User::role('admin')->firstOrFail();

        MpesaTransaction::create([
            'tenancy_id' => null,
            'landlord_id' => $landlord->id,
            'initiated_by' => $landlord->id,
            'phone' => '254712345678',
            'amount' => 300,
            'purpose' => 'sms_topup',
            'checkout_request_id' => 'checkout-admin-view-1',
            'status' => 'success',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(AdminPaymentsIndex::class)
            ->assertSee('SMS Wallet Top-up')
            ->assertSee($landlord->name);

        // Filtering by the other landlord must not show this row.
        $component->set('landlordId', $otherLandlord->id)
            ->assertDontSee('checkout-admin-view-1');

        // Filtering by the correct landlord must still show it.
        $component->set('landlordId', $landlord->id)
            ->assertSee('SMS Wallet Top-up');
    }

    // --- G-2: recipient resolution and fail-closed billing ------------------------------

    /** A fresh landlord + property + house + tenancy, fully self-contained — never relies on seeded data, so two of these under two different landlords can be built per test. */
    private function tenancyForNewLandlord(string $houseSuffix, string $status = 'active'): Tenancy
    {
        $landlord = User::factory()->create(['status' => 'active']);
        $landlord->assignRole('landlord');
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => "Property {$houseSuffix}", 'type' => 'apartment', 'status' => 'active']);
        $block = Block::create(['property_id' => $property->id, 'name' => "Block {$houseSuffix}"]);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => "H-{$houseSuffix}",
            'monthly_rent' => 15000, 'deposit_amount' => 15000, 'status' => 'occupied',
        ]);
        $tenant = Tenant::create(['name' => "Tenant {$houseSuffix}", 'phone' => '07'.random_int(10000000, 99999999), 'payment_due_day' => 1]);

        return Tenancy::create([
            'tenant_id' => $tenant->id, 'house_id' => $house->id,
            'move_in_date' => now()->subMonths(6)->format('Y-m-d'),
            'move_out_date' => $status !== 'active' ? now()->subMonth()->format('Y-m-d') : null,
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => $status,
        ]);
    }

    private function fakeSuccessfulSms(): void
    {
        config(['services.africastalking.api_key' => 'fake-at-key']);
        Http::fake([
            '*africastalking.com*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]]),
        ]);
    }

    public function test_tenant_with_a_related_tenancy_charges_that_tenancys_landlord(): void
    {
        $this->seed();
        $tenancy = $this->tenancyForNewLandlord('rel-1');
        $landlordId = $tenancy->house->block->property->landlord_id;
        SmsWalletEntry::create(['landlord_id' => $landlordId, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        $this->fakeSuccessfulSms();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->format('Y-m-d'), 'amount_expected' => 15000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        app(NotificationService::class)->notify(
            $tenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms'], $charge,
        );

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($landlordId));
        $this->assertEquals('sent', AppNotification::where('channel', 'sms')->latest()->first()->status);
    }

    public function test_tenant_without_a_related_tenancy_falls_back_to_current_tenancy(): void
    {
        $this->seed();
        $tenancy = $this->tenancyForNewLandlord('fallback-1');
        $landlordId = $tenancy->house->block->property->landlord_id;
        SmsWalletEntry::create(['landlord_id' => $landlordId, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        $this->fakeSuccessfulSms();

        // $related is a Message, which has no tenancy() relation at all —
        // the resolution must fall back to currentTenancy(), not fail.
        $message = \App\Models\Message::create([
            'sender_id' => $landlordId, 'tenant_id' => $tenancy->tenant_id, 'scope' => 'tenant',
            'subject' => 'Unrelated', 'body' => 'Unrelated.',
        ]);

        app(NotificationService::class)->notify(
            $tenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms'], $message,
        );

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($landlordId));
        $this->assertEquals('sent', AppNotification::where('channel', 'sms')->latest()->first()->status);
    }

    public function test_tenant_with_no_resolvable_tenancy_is_rejected_without_sending(): void
    {
        $tenant = Tenant::create(['name' => 'Homeless Tenant', 'phone' => '0700111222', 'payment_due_day' => 1]);
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify(
            $tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms'],
        );

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking.com'));
        $this->assertEquals('failed', AppNotification::where('channel', 'sms')->latest()->first()->status);
        $this->assertDatabaseCount('sms_wallet_entries', 0);
    }

    public function test_related_tenancys_landlord_is_charged_even_when_the_current_tenancy_belongs_to_a_different_landlord(): void
    {
        $this->seed();
        $oldTenancy = $this->tenancyForNewLandlord('old-landlord', status: 'completed');
        $oldLandlordId = $oldTenancy->house->block->property->landlord_id;

        // Same tenant, a newer tenancy at a different landlord's property —
        // this is now their currentTenancy(), but must NOT be billed here.
        $newTenancy = Tenancy::create([
            'tenant_id' => $oldTenancy->tenant_id,
            'house_id' => $this->tenancyForNewLandlord('new-landlord')->house_id,
            'move_in_date' => now()->subDays(5)->format('Y-m-d'),
            'monthly_rent' => 15000, 'deposit_required' => 15000, 'deposit_paid' => 15000,
            'status' => 'active',
        ]);
        $newLandlordId = $newTenancy->house->block->property->landlord_id;
        $this->assertNotEquals($oldLandlordId, $newLandlordId);

        SmsWalletEntry::create(['landlord_id' => $oldLandlordId, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        $this->fakeSuccessfulSms();

        $charge = RentCharge::create([
            'tenancy_id' => $oldTenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now()->format('Y-m-d'), 'amount_expected' => 15000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        app(NotificationService::class)->notify(
            $oldTenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms'], $charge,
        );

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($oldLandlordId), "The old tenancy's own landlord must be charged.");
        $this->assertEquals(0.0, app(SmsWalletService::class)->balance($newLandlordId), "The current-but-unrelated landlord must not be charged.");
    }

    public function test_landlord_recipient_charges_their_own_wallet(): void
    {
        $this->seed();
        $landlord = User::factory()->create(['status' => 'active', 'phone' => '0700333444']);
        $landlord->assignRole('landlord');
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify($landlord, 'sms_wallet_topped_up', 'Test', 'Body', ['sms']);

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($landlord->id));
        $this->assertEquals('sent', AppNotification::where('channel', 'sms')->latest()->first()->status);
    }

    public function test_manager_recipient_charges_the_delegating_landlords_wallet(): void
    {
        $this->seed();
        $landlord = User::factory()->create(['status' => 'active']);
        $landlord->assignRole('landlord');
        $managerUser = User::factory()->create(['status' => 'active', 'phone' => '0700555666']);
        $managerUser->assignRole('manager');
        Manager::create([
            'landlord_id' => $landlord->id, 'user_id' => $managerUser->id, 'name' => $managerUser->name,
            'phone' => '0700555666', 'email' => $managerUser->email, 'all_properties' => true, 'status' => 'active',
            'token' => Manager::generateToken(), 'expires_at' => now()->addDays(7), 'activated_at' => now(),
        ]);
        SmsWalletEntry::create(['landlord_id' => $landlord->id, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify($managerUser, 'sms_wallet_topped_up', 'Test', 'Body', ['sms']);

        $this->assertEquals(8.0, app(SmsWalletService::class)->balance($landlord->id), "The delegating landlord's wallet must be charged, not the manager's.");
        $this->assertEquals('sent', AppNotification::where('channel', 'sms')->latest()->first()->status);
    }

    public function test_admin_recipient_is_rejected_without_sending(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $admin->update(['phone' => '0700777888']);
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify($admin, 'sms_wallet_topped_up', 'Test', 'Body', ['sms']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking.com'));
        $this->assertEquals('failed', AppNotification::where('channel', 'sms')->latest()->first()->status);
        $this->assertDatabaseCount('sms_wallet_entries', 0);
    }

    public function test_other_unresolvable_recipient_type_is_rejected_without_sending(): void
    {
        $this->seed();
        // Neither a Tenant nor a User — exercises the generic fallback,
        // not just the two special-cased notifiable types.
        $notifiable = Manager::create([
            'landlord_id' => User::role('landlord')->firstOrFail()->id, 'name' => 'Bare Manager Record',
            'phone' => '0700999000', 'email' => 'bare-manager-record@example.test', 'all_properties' => true, 'status' => 'active',
            'token' => Manager::generateToken(), 'expires_at' => now()->addDays(7),
        ]);
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify($notifiable, 'sms_wallet_topped_up', 'Test', 'Body', ['sms']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking.com'));
        $this->assertEquals('failed', AppNotification::where('channel', 'sms')->latest()->first()->status);
        $this->assertDatabaseCount('sms_wallet_entries', 0);
    }

    public function test_insufficient_balance_rejects_without_sending(): void
    {
        $this->seed();
        $tenancy = $this->tenancyForNewLandlord('insufficient-1');
        \App\Models\Setting::set('sms_rate_per_message', '2.00');
        // No wallet entries at all — balance is 0, below the rate.
        $this->fakeSuccessfulSms();

        app(NotificationService::class)->notify($tenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking.com'));
        $this->assertEquals('failed', AppNotification::where('channel', 'sms')->latest()->first()->status);
        $this->assertDatabaseCount('sms_wallet_entries', 0);
    }

    public function test_failed_sms_delivery_never_debits_the_wallet(): void
    {
        $this->seed();
        $tenancy = $this->tenancyForNewLandlord('send-fail-1');
        $landlordId = $tenancy->house->block->property->landlord_id;
        SmsWalletEntry::create(['landlord_id' => $landlordId, 'type' => 'credit', 'amount' => 10]);
        \App\Models\Setting::set('sms_rate_per_message', '2.00');

        // No api_key configured at all — SmsService::send() fails before
        // ever making an HTTP call, deterministically simulating a
        // provider-side delivery failure without needing Http::fake.
        config(['services.africastalking.api_key' => null]);

        app(NotificationService::class)->notify($tenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms']);

        $this->assertEquals(10.0, app(SmsWalletService::class)->balance($landlordId), 'Balance must be untouched — nothing was actually sent.');
        $this->assertEquals('failed', AppNotification::where('channel', 'sms')->latest()->first()->status);
        $this->assertDatabaseCount('sms_wallet_entries', 1); // only the original credit
    }

    /**
     * The one scenario that genuinely needs a mock rather than real wallet
     * state: debit() refusing after the SMS has already, successfully,
     * irreversibly gone out. Both SmsService::send() (via Http::fake) and
     * SmsWalletService::debit() (via a full mock) are controlled
     * deterministically, since reproducing the real race condition itself
     * isn't practical in a synchronous test.
     */
    public function test_successful_send_with_failed_debit_records_a_discrepancy_without_resending(): void
    {
        $this->seed();
        $tenancy = $this->tenancyForNewLandlord('race-1');
        $landlordId = $tenancy->house->block->property->landlord_id;
        $this->fakeSuccessfulSms();

        $this->mock(SmsWalletService::class, function ($mock) use ($landlordId) {
            $mock->shouldReceive('rate')->andReturn(2.0);
            $mock->shouldReceive('balance')->with($landlordId)->andReturn(10.0); // passes the pre-check
            $mock->shouldReceive('debit')->once()->with($landlordId, 2.0, \Mockery::type(AppNotification::class))->andReturn(false);
            $mock->shouldReceive('recordUnbilledSend')->once()->with($landlordId, 2.0, \Mockery::type(AppNotification::class));
        });

        app(NotificationService::class)->notify($tenancy->tenant, 'rent_due_soon_test', 'Rent Reminder', 'Body', ['sms']);

        // Exactly one send — the discrepancy must never trigger a resend.
        Http::assertSentCount(1);

        $notification = AppNotification::where('channel', 'sms')->latest()->first();
        $this->assertEquals('sent', $notification->status, 'The SMS genuinely went out — status reflects delivery, not billing outcome.');
        $this->assertNotNull($notification->sent_at, 'The SMS genuinely went out — sent_at must reflect that regardless of the billing outcome.');

        // The forced debit entry itself is verified above via the mock's
        // own ->once()->with(...) expectation on recordUnbilledSend() —
        // SmsWalletService is fully mocked here, so no real SmsWalletEntry
        // row is written in this test; the happy path (real, unmocked
        // debit() writing a real SmsWalletEntry) is already covered by
        // test_sms_reminder_debits_the_wallet_only_after_a_successful_send
        // above.
    }
}
