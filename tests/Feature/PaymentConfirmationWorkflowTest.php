<?php

namespace Tests\Feature;

use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\PayRent;
use App\Models\AppNotification;
use App\Models\House;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PendingActionsService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every Payment row used to be treated as confirmed money the instant it
 * existed — correct for an M-Pesa callback (Safaricom already verified it)
 * or a landlord typing in something they'd already checked, but wrong for
 * a tenant's own unverified "I paid offline" claim. This is the real
 * confirmation workflow that was missing: a tenant can submit a claim, it
 * stays out of the rent ledger until a landlord confirms or disputes it,
 * and both sides are notified at every step.
 */
class PaymentConfirmationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Payment Workflow Tenant',
            'phone' => '0700777888',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => 20000,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_a_tenant_submitted_offline_payment_does_not_count_toward_the_ledger_until_confirmed(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenancy->tenant, $tenancy->tenant->name, 'payment-workflow-tenant@example.test', 'password');

        Livewire::actingAs($tenantUser)
            ->test(PayRent::class)
            ->call('openOfflineForm')
            ->set('offline_method', 'bank')
            ->set('offline_amount', '20000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->set('offline_reference', 'FT12345678')
            ->call('submitOfflinePayment')
            ->assertHasNoErrors();

        $payment = Payment::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('awaiting_landlord_confirmation', $payment->status);
        $this->assertEquals('tenant_submitted_offline', $payment->source);

        // Still owed in full — the claim hasn't been confirmed yet.
        $charge->refresh();
        $this->assertEquals(0.0, (float) $charge->amount_paid);
        $this->assertEquals('overdue', $charge->status);
        $this->assertEquals(20000.0, $tenancy->balance());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'payment_awaiting_confirmation')
                ->exists()
        );
    }

    public function test_landlord_confirming_a_payment_updates_the_ledger_and_notifies_the_tenant(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 20000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'bank', 'source' => 'tenant_submitted_offline',
            'status' => 'awaiting_landlord_confirmation', 'recorded_by' => $tenancy->tenant->user_id ?? $landlord->id,
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmPayment', $payment->id)
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertEquals('confirmed', $payment->status);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertEquals($landlord->id, $payment->confirmed_by);

        $charge->refresh();
        $this->assertEquals(20000.0, (float) $charge->amount_paid);
        $this->assertEquals('paid', $charge->status);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'payment_confirmed_by_landlord')
                ->exists()
        );
    }

    public function test_landlord_disputing_a_payment_keeps_it_out_of_the_ledger_and_requires_a_reason(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 20000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'bank', 'source' => 'tenant_submitted_offline',
            'status' => 'awaiting_landlord_confirmation', 'recorded_by' => $landlord->id,
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('disputePayment', $payment->id, 'No such transfer found on our bank statement.')
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertEquals('disputed', $payment->status);
        $this->assertEquals('No such transfer found on our bank statement.', $payment->dispute_reason);
        $this->assertEquals($landlord->id, $payment->disputed_by);

        $charge->refresh();
        $this->assertEquals(0.0, (float) $charge->amount_paid);
        $this->assertEquals('overdue', $charge->status);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'payment_disputed')
                ->exists()
        );
    }

    public function test_a_landlord_can_reverse_an_earlier_confirmation_by_disputing_it(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 20000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'bank', 'source' => 'tenant_submitted_offline',
            'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $landlord->id, 'recorded_by' => $landlord->id,
        ]);
        $charge->recalculateStatus();
        $this->assertEquals('paid', $charge->fresh()->status);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('disputePayment', $payment->id, 'Turned out to be a duplicate entry.')
            ->assertHasNoErrors();

        $this->assertEquals('disputed', $payment->fresh()->status);
        $this->assertEquals('overdue', $charge->fresh()->status);
    }

    public function test_an_mpesa_stk_payment_is_still_confirmed_immediately_with_no_landlord_step(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 5000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'mpesa', 'source' => 'system_payment',
            'reference_number' => 'QGH7XJ001', 'recorded_by' => User::factory()->create()->id,
        ]);

        $this->assertEquals('confirmed', $payment->status);
        $this->assertEquals(5000.0, (float) $charge->fresh()->amount_paid);
    }

    public function test_the_landlord_badge_counts_only_claims_awaiting_confirmation_within_their_own_scope(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 20000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'bank', 'source' => 'tenant_submitted_offline',
            'status' => 'awaiting_landlord_confirmation', 'recorded_by' => $landlord->id,
        ]);

        // A confirmed payment, and a disputed one, must not inflate the count.
        Payment::create([
            'tenancy_id' => $tenancy->id, 'rent_charge_id' => $charge->id, 'amount' => 1000,
            'payment_date' => now()->format('Y-m-d'), 'method' => 'cash', 'source' => 'manual_entry',
            'recorded_by' => $landlord->id,
        ]);

        $this->assertEquals(1, app(PendingActionsService::class)->paymentsAwaitingConfirmationCount($landlord));

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $this->assertEquals(0, app(PendingActionsService::class)->paymentsAwaitingConfirmationCount($otherLandlord));
    }

    public function test_evidence_uploaded_with_an_offline_claim_is_attached_and_viewable_by_the_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20000, 'amount_paid' => 0, 'status' => 'overdue',
        ]);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenancy->tenant, $tenancy->tenant->name, 'evidence-tenant@example.test', 'password');

        Livewire::actingAs($tenantUser)
            ->test(PayRent::class)
            ->call('openOfflineForm')
            ->set('offline_method', 'bank')
            ->set('offline_amount', '20000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->set('offline_evidence', UploadedFile::fake()->create('slip.pdf', 200, 'application/pdf'))
            ->call('submitOfflinePayment')
            ->assertHasNoErrors();

        $payment = Payment::where('tenancy_id', $tenancy->id)->firstOrFail();
        $document = $payment->documents()->first();

        $this->assertNotNull($document);
        $this->assertEquals('payment_confirmation', $document->category);
        $this->assertTrue($landlord->can('view', $document));
    }
}
