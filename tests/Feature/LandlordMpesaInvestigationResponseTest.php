<?php

namespace Tests\Feature;

use App\Livewire\Tenancies\MpesaInvestigation;
use App\Models\AppNotification;
use App\Models\Manager;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\MpesaInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Landlord-to-admin-to-tenant payment-investigation workflow, built after a
 * tenant's cancellation-request notification landed the landlord on the
 * general tenancy overview with no indication of what to do. The landlord's
 * two actions here ("I received this payment" / "I did not receive this
 * payment") are pure acknowledgements — see
 * MpesaInvestigationService::recordLandlordReceivedPayment()/
 * recordLandlordDidNotReceivePayment()'s docblocks for exactly what they
 * do and do not do. Covers both rent and deposit, per the approved plan.
 */
class LandlordMpesaInvestigationResponseTest extends TestCase
{
    use RefreshDatabase;

    private function tenancyWithStuckTransaction(string $purpose = 'deposit', float $amount = 5000): array
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id,
            'initiated_by' => User::factory()->create()->id,
            'phone' => '254712345678',
            'amount' => $amount,
            'purpose' => $purpose,
            'checkout_request_id' => 'checkout-landlord-response-'.$purpose.'-'.uniqid(),
            'status' => 'pending',
            'cancellation_requested_at' => now(),
        ]);

        return [$tenancy, $landlord, $transaction];
    }

    private function restrictedManagerFor(User $landlord, int ...$excludePropertyIds): User
    {
        $otherProperty = \App\Models\Property::where('landlord_id', $landlord->id)
            ->whereNotIn('id', $excludePropertyIds)
            ->first();

        // No second property exists on this seeded landlord — give the
        // manager access to nothing at all instead, which is just as valid
        // a "does not include this transaction's property" fixture.
        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Restricted Manager',
            'phone' => '0700000099',
            'email' => 'restricted-manager-'.uniqid().'@test.test',
            'all_properties' => false,
            'permissions' => ['tenancies.view'],
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        if ($otherProperty) {
            $manager->properties()->attach($otherProperty->id);
        }

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);

        return $managerUser;
    }

    public function test_landlord_can_view_the_investigation_page_for_their_own_transaction(): void
    {
        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction();

        // 'Payment Investigation' lives in the x-slot:header, which (like
        // Tenancies\Show's own identical header) renders as a separate
        // block from the component's own $slot — not present in a
        // Livewire::test() component's own ->html(), so it's not asserted
        // here; the content that IS part of the component's own render()
        // output is.
        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $transaction])
            ->assertOk()
            ->assertSee('KSh 5,000.00')
            ->assertSee('I received this payment')
            ->assertSee('I did not receive this payment');
    }

    /**
     * Requirement 4 (authorization refinement): a manager must never have
     * unrestricted access to their landlord's whole portfolio — only to
     * the specific properties they've been delegated.
     */
    public function test_a_manager_without_access_to_this_property_cannot_view_or_respond(): void
    {
        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction();
        $restrictedManager = $this->restrictedManagerFor($landlord, $tenancy->house->block->property_id);

        Livewire::actingAs($restrictedManager)
            ->test(MpesaInvestigation::class, ['transaction' => $transaction])
            ->assertForbidden();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(MpesaInvestigationService::class)->recordLandlordReceivedPayment($transaction, $restrictedManager);
    }

    public function test_landlord_confirming_received_notifies_admin_and_tenant_without_crediting_anything(): void
    {
        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction('deposit', 40000);
        $admin = User::role('admin')->firstOrFail();
        $paymentCountBefore = Payment::count();

        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $transaction])
            ->call('confirmReceived')
            ->assertSee('received');

        $transaction->refresh();
        $this->assertEquals(MpesaTransaction::LANDLORD_RESPONSE_RECEIVED, $transaction->landlord_response);
        $this->assertEquals($landlord->id, $transaction->landlord_response_by);
        $this->assertNotNull($transaction->landlord_response_at);

        // Never credited, never resolved — only admin's resolveAsSuccessful()
        // (still admin-only, unchanged) may do that.
        $this->assertEquals('pending', $transaction->status);
        $this->assertNull($transaction->deposit_payment_id);
        $this->assertNull($transaction->payment_id);
        $this->assertEquals($paymentCountBefore, Payment::count());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'landlord_confirmed_mpesa_payment_received')
                ->exists()
        );

        // Wording distinguishes acknowledgement from an actual financial
        // record (clarification 5) — never claims the payment is recorded.
        $tenantNotification = AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->where('notifiable_id', $tenancy->tenant->id)
            ->where('event_type', 'mpesa_landlord_confirmed_receipt')
            ->first();
        $this->assertNotNull($tenantNotification);
        $this->assertStringContainsString('awaiting verification and recording', $tenantNotification->body);
        $this->assertStringContainsString('do not initiate another payment', $tenantNotification->body);
        $this->assertStringNotContainsString('has been recorded', $tenantNotification->body);
    }

    public function test_landlord_saying_not_received_only_notifies_admin_and_does_not_touch_the_transaction(): void
    {
        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction('rent', 5000);
        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $transaction])
            ->call('confirmNotReceived');

        $transaction->refresh();
        $this->assertEquals(MpesaTransaction::LANDLORD_RESPONSE_NOT_RECEIVED, $transaction->landlord_response);
        $this->assertEquals('pending', $transaction->status, 'Must not itself cancel, fail, or otherwise change the original transaction.');

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'landlord_denies_mpesa_payment_received')
                ->exists()
        );

        // Nothing has actually changed for the tenant yet — no new tenant
        // notification until admin resolves it.
        $this->assertFalse(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->whereIn('event_type', ['mpesa_landlord_confirmed_receipt', 'rent_payment_failed'])
                ->exists()
        );
    }

    public function test_a_second_landlord_response_is_rejected_and_does_not_duplicate_notifications(): void
    {
        [, $landlord, $transaction] = $this->tenancyWithStuckTransaction();
        $admin = User::role('admin')->firstOrFail();

        app(MpesaInvestigationService::class)->recordLandlordReceivedPayment($transaction, $landlord);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(MpesaInvestigationService::class)->recordLandlordDidNotReceivePayment($transaction, $landlord);
        } finally {
            $this->assertEquals(
                MpesaTransaction::LANDLORD_RESPONSE_RECEIVED,
                $transaction->fresh()->landlord_response,
                'The first response must stand — a second attempt must never overwrite it.'
            );
            // 2, not 1: NotificationService::notify() creates one
            // AppNotification row per channel, and the admin notification
            // is sent on both 'in_app' and 'email' — both rows come from
            // the single successful first call. A rejected second attempt
            // must add none beyond that.
            $this->assertEquals(
                2,
                AppNotification::where('notifiable_type', $admin->getMorphClass())
                    ->where('notifiable_id', $admin->id)
                    ->whereIn('event_type', ['landlord_confirmed_mpesa_payment_received', 'landlord_denies_mpesa_payment_received'])
                    ->count(),
                'A rejected second attempt must not notify admin again.'
            );
        }
    }

    public function test_landlord_cannot_respond_to_an_already_resolved_transaction(): void
    {
        [, $landlord, $transaction] = $this->tenancyWithStuckTransaction();
        $transaction->update(['status' => 'cancelled']);

        $this->expectException(\InvalidArgumentException::class);

        app(MpesaInvestigationService::class)->recordLandlordReceivedPayment($transaction, $landlord);
    }

    /**
     * End-to-end for the "not received" branch: the tenant stays blocked
     * from retrying until admin actually resolves the original transaction
     * — the landlord's response alone never lifts the Finding 4 retry block.
     */
    public function test_tenant_remains_blocked_until_admin_resolves_after_landlord_says_not_received(): void
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
                'MerchantRequestID' => 'merchant-retry-after-landlord-1',
                'CheckoutRequestID' => 'checkout-retry-after-landlord-1',
                'ResponseCode' => '0',
            ]),
        ]);

        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction('deposit', 5000);
        $admin = User::role('admin')->firstOrFail();

        app(MpesaInvestigationService::class)->recordLandlordDidNotReceivePayment($transaction, $landlord);

        $tenantUser = User::factory()->create();
        $tenantUser->syncRoles(['tenant']);
        $tenancy->tenant->update(['user_id' => $tenantUser->id]);

        Livewire::actingAs($tenantUser)
            ->test(\App\Livewire\Tenant\PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasErrors('phone');

        // Only once admin actually resolves it does the tenant get told —
        // and only then is the retry block lifted.
        app(MpesaInvestigationService::class)->resolveAsFailed($transaction, $admin, 'Tenant confirmed the PIN prompt was declined.');

        Livewire::actingAs($tenantUser)
            ->test(\App\Livewire\Tenant\PayDeposit::class)
            ->set('phone', '0712345678')
            ->set('amount', '5000')
            ->call('pay')
            ->assertHasNoErrors();
    }

    /** mpesa_callback_missed now carries the transaction (not the tenancy) as $related, so the dedicated investigation page can be linked to directly. */
    public function test_automated_escalation_notification_carries_the_transaction_as_related(): void
    {
        [, $landlord, $transaction] = $this->tenancyWithStuckTransaction();
        $transaction->update(['cancellation_requested_at' => null]); // isolate the automated-escalation path from the tenant-request one

        app(MpesaInvestigationService::class)->escalateForManualVerification($transaction, null, 'No answer after repeated queries.');

        $notification = AppNotification::where('event_type', 'mpesa_callback_missed')->latest()->first();
        $this->assertNotNull($notification);
        $this->assertEquals($transaction->getMorphClass(), $notification->related_type);
        $this->assertEquals($transaction->id, $notification->related_id);
        $this->assertSame(route('manage.mpesa-investigations.show', $transaction->id), $notification->actionUrl($landlord));
    }

    /** The page itself must distinguish why it exists, not treat a tenant-raised request and an automated escalation identically. */
    public function test_the_investigation_page_shows_a_different_reason_for_a_tenant_request_vs_an_automated_escalation(): void
    {
        [, $landlord, $tenantRequested] = $this->tenancyWithStuckTransaction();

        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $tenantRequested])
            ->assertSee('asked us to look into this payment');

        [, , $escalated] = $this->tenancyWithStuckTransaction();
        $escalated->update(['cancellation_requested_at' => null]);
        app(MpesaInvestigationService::class)->escalateForManualVerification($escalated, null, 'Safaricom has not confirmed what happened after repeated queries.');

        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $escalated->fresh()])
            ->assertSee('Safaricom has not confirmed what happened after repeated queries.')
            ->assertDontSee('asked us to look into this payment');
    }

    public function test_the_full_workflow_also_works_for_a_rent_purpose_transaction(): void
    {
        [$tenancy, $landlord, $transaction] = $this->tenancyWithStuckTransaction('rent', 7000);

        Livewire::actingAs($landlord)
            ->test(MpesaInvestigation::class, ['transaction' => $transaction])
            ->call('confirmReceived')
            ->assertHasNoErrors();

        $this->assertEquals(MpesaTransaction::LANDLORD_RESPONSE_RECEIVED, $transaction->fresh()->landlord_response);
        $this->assertEquals('pending', $transaction->fresh()->status);
    }
}
