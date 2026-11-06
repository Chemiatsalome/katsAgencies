<?php

namespace Tests\Feature;

use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\Deposit as TenantDeposit;
use App\Livewire\Tenant\PayDeposit;
use App\Models\AppNotification;
use App\Models\DepositPayment;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 of the tenant module audit. Root cause of the reported bug (My
 * Deposit had no manual-payment option) was architectural, not a missing
 * form: DepositPayment had no confirmation lifecycle at all — every row
 * ever created (M-Pesa success, a landlord's own entry) was treated as
 * confirmed money the instant it existed, with no 'awaiting confirmation'
 * state to put an unverified tenant claim into. This phase adds that
 * lifecycle (mirroring Payment's exactly — see migration
 * add_confirmation_workflow_to_deposit_payments_table), then builds the
 * same offline-claim UI PayRent already has on top of it. The
 * non-negotiable rule throughout: a manual deposit submission must remain
 * pending verification and must never, by itself, credit the deposit
 * ledger.
 */
class TenantDepositModuleTest extends TestCase
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

    public function test_tenant_can_submit_an_offline_deposit_claim_and_it_does_not_credit_the_ledger(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        $outstandingBefore = $tenancy->fresh()->depositOutstanding();
        $this->assertGreaterThan(0, $outstandingBefore, 'Seeded tenancy is expected to have an outstanding deposit.');

        Livewire::actingAs($user)
            ->test(PayDeposit::class)
            ->call('openOfflineForm')
            ->set('offline_method', 'bank')
            ->set('offline_amount', (string) $outstandingBefore)
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->set('offline_reference', 'BANK-DEP-001')
            ->call('submitOfflinePayment')
            ->assertHasNoErrors();

        $payment = DepositPayment::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('awaiting_landlord_confirmation', $payment->status);
        $this->assertEquals((float) $outstandingBefore, (float) $payment->amount);

        // The non-negotiable rule: an unverified claim must not move the ledger.
        $this->assertEquals($outstandingBefore, $tenancy->fresh()->depositOutstanding());
        $this->assertEquals(0.0, (float) $tenancy->fresh()->deposit_paid - (float) $tenancy->deposit_paid);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'deposit_payment_awaiting_confirmation')
                ->exists()
        );
    }

    public function test_an_offline_deposit_claim_with_evidence_is_viewable_by_the_tenant_who_uploaded_it(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Livewire::actingAs($user)
            ->test(PayDeposit::class)
            ->call('openOfflineForm')
            ->set('offline_method', 'cash')
            ->set('offline_amount', '1000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->set('offline_evidence', UploadedFile::fake()->create('deposit-slip.pdf', 100, 'application/pdf'))
            ->call('submitOfflinePayment')
            ->assertHasNoErrors();

        $payment = DepositPayment::where('tenancy_id', $tenancy->id)->firstOrFail();
        $document = $payment->documents()->firstOrFail();

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_landlord_confirming_a_deposit_claim_credits_the_ledger_and_notifies_tenant(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        $outstandingBefore = $tenancy->depositOutstanding();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => $outstandingBefore, 'payment_date' => now(),
            'method' => 'bank', 'recorded_by' => $landlord->id, 'status' => 'awaiting_landlord_confirmation',
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmDepositPayment', $payment->id)
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertEquals('confirmed', $payment->status);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertEquals($landlord->id, $payment->confirmed_by);

        // Only now does it reduce what's outstanding.
        $this->assertEquals(0.0, $tenancy->fresh()->depositOutstanding());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'deposit_payment_confirmed_by_landlord')
                ->exists()
        );
    }

    public function test_landlord_disputing_a_deposit_claim_does_not_credit_and_notifies_tenant(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;
        $outstandingBefore = $tenancy->depositOutstanding();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => $outstandingBefore, 'payment_date' => now(),
            'method' => 'bank', 'recorded_by' => $landlord->id, 'status' => 'awaiting_landlord_confirmation',
        ]);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('disputeDepositPayment', $payment->id, 'No such transfer received.')
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertEquals('disputed', $payment->status);
        $this->assertEquals('No such transfer received.', $payment->dispute_reason);

        // A dispute must not credit the ledger either.
        $this->assertEquals($outstandingBefore, $tenancy->fresh()->depositOutstanding());

        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
                ->where('notifiable_id', $tenancy->tenant->id)
                ->where('event_type', 'deposit_payment_disputed')
                ->exists()
        );
    }

    public function test_a_landlord_cannot_act_on_another_landlords_tenant_deposit_payment(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 1000, 'payment_date' => now(),
            'method' => 'bank', 'status' => 'awaiting_landlord_confirmation',
        ]);

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');

        // TenancyShow::mount() itself already authorizes 'view' on the
        // tenancy — a landlord who doesn't own it can't even reach the
        // component confirmDepositPayment() lives on, let alone call it.
        Livewire::actingAs($otherLandlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->assertForbidden();
    }

    /**
     * Defense in depth, independent of the mount-level check above:
     * confirmDepositPayment()/disputeDepositPayment() scope their own
     * lookup by `where('tenancy_id', $this->tenancy->id)` — a landlord
     * viewing one of their OWN tenancies must not be able to confirm a
     * deposit payment that actually belongs to a different tenancy by
     * guessing/passing its ID.
     */
    public function test_confirming_a_deposit_payment_is_scoped_to_the_tenancy_being_viewed(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancies = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->active()->get();
        $this->assertGreaterThan(1, $tenancies->count(), 'Seeded landlord is expected to have more than one active tenancy.');

        $viewedTenancy = $tenancies[0];
        $otherTenancy = $tenancies[1];

        $payment = DepositPayment::create([
            'tenancy_id' => $otherTenancy->id, 'amount' => 1000, 'payment_date' => now(),
            'method' => 'bank', 'status' => 'awaiting_landlord_confirmation',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $viewedTenancy])
            ->call('confirmDepositPayment', $payment->id);
    }

    /** Regression: both pre-existing write paths must still default to 'confirmed' and credit immediately, unchanged. */
    public function test_a_landlord_manually_recording_a_deposit_still_credits_immediately(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;
        $outstandingBefore = $tenancy->depositOutstanding();

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\Deposits\RecordDeposit::class, ['tenancy' => $tenancy])
            ->set('amount', (string) $outstandingBefore)
            ->set('method', 'cash')
            ->call('save')
            ->assertHasNoErrors();

        $payment = DepositPayment::where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->assertEquals('confirmed', $payment->status);
        $this->assertEquals(0.0, $tenancy->fresh()->depositOutstanding());
    }

    public function test_pending_and_disputed_deposit_payments_are_annotated_not_shown_as_plain_credits(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 2000, 'payment_date' => now(),
            'method' => 'bank', 'status' => 'awaiting_landlord_confirmation',
        ]);
        DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 3000, 'payment_date' => now(),
            'method' => 'cash', 'status' => 'disputed', 'dispute_reason' => 'Wrong amount.',
        ]);

        Livewire::actingAs($user)
            ->test(TenantDeposit::class)
            ->assertSee('awaiting confirmation')
            ->assertSee('disputed')
            ->assertSee('Wrong amount.');
    }

    /**
     * Requested verification: a failed manual submission or evidence
     * upload must never leave an incomplete record that affects the
     * deposit ledger.
     */
    public function test_an_invalid_offline_submission_creates_no_record_and_does_not_touch_the_ledger(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $depositPaidBefore = $tenancy->fresh()->deposit_paid;
        $rowCountBefore = DepositPayment::count();

        Livewire::actingAs($user)
            ->test(PayDeposit::class)
            ->call('openOfflineForm')
            ->set('offline_method', 'bank')
            ->set('offline_amount', '0') // fails min:1 validation
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->call('submitOfflinePayment')
            ->assertHasErrors('offline_amount');

        $this->assertEquals($rowCountBefore, DepositPayment::count());
        $this->assertEquals((float) $depositPaidBefore, (float) $tenancy->fresh()->deposit_paid);
    }

    /**
     * Even a claim left "incomplete" in the sense that no evidence was
     * ever attached (upload skipped, or the only part that failed) is
     * still fully safe and fully usable: it stays out of the ledger exactly
     * like one with evidence, and the landlord can still confirm or
     * dispute it normally — a missing attachment never blocks or corrupts
     * the workflow, because the ledger gate is `status`, never
     * `documents()->exists()`.
     */
    public function test_a_claim_with_no_evidence_attached_still_cannot_affect_the_ledger_until_confirmed(): void
    {
        $this->seed();
        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;
        $depositPaidBefore = $tenancy->depositOutstanding();

        $payment = DepositPayment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 5000, 'payment_date' => now(),
            'method' => 'bank', 'status' => 'awaiting_landlord_confirmation',
        ]);

        $this->assertCount(0, $payment->documents);
        $this->assertEquals($depositPaidBefore, $tenancy->fresh()->depositOutstanding());

        // Still fully actionable despite having no evidence attached.
        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('confirmDepositPayment', $payment->id)
            ->assertHasNoErrors();

        $this->assertEquals('confirmed', $payment->fresh()->status);
    }
}
