<?php

namespace Tests\Feature;

use App\Livewire\Admin\Disbursements\Index as AdminDisbursementsIndex;
use App\Livewire\Admin\Disbursements\Show as AdminDisbursementsShow;
use App\Livewire\Landlord\Payouts\Index as LandlordPayoutsIndex;
use App\Models\AppNotification;
use App\Models\Disbursement;
use App\Models\House;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\PendingActionsService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin -> landlord offline payout workflow: an admin records a transfer
 * that was made outside the system (M-Pesa/bank), the landlord confirms or
 * disputes it, and an admin can correct a bad entry afterwards. Mirrors the
 * tenant -> landlord confirmation workflow already covered by
 * PaymentConfirmationWorkflowTest, just for the other side of the ledger.
 */
class LandlordPayoutWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createPlatformPayment(User $landlord, float $amount = 20000): Payment
    {
        $tenancy = $this->createTenancyFor($landlord);

        return Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => $amount,
            'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa',
            'collection_mode' => 'platform',
            'recorded_by' => $landlord->id,
            'source' => 'system_payment',
        ]);
    }

    private function createTenancyFor(User $landlord, ?Property $property = null): Tenancy
    {
        $property ??= $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->first()
            ?? House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Payout Workflow Tenant '.uniqid(),
            'phone' => '0700'.random_int(100000, 999999),
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

    public function test_recording_a_disbursement_does_not_mark_it_received_and_notifies_the_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        Livewire::actingAs($admin)
            ->test(AdminDisbursementsIndex::class)
            ->call('startDisburse', $landlord->id)
            ->set('amount', '12000')
            ->set('reference', 'MPESA-REF-001')
            ->call('confirmDisburse')
            ->assertHasNoErrors();

        $disbursement = Disbursement::where('landlord_id', $landlord->id)->firstOrFail();
        $this->assertEquals('awaiting_landlord_confirmation', $disbursement->status);
        $this->assertEquals(12000.0, (float) $disbursement->amount);
        $this->assertNull($disbursement->confirmed_at);

        // Already removed from "owed" the instant it was recorded — the
        // transfer is asserted to have happened; confirmation only
        // acknowledges it, never re-triggers this subtraction.
        $this->assertEquals(8000.0, app(DisbursementService::class)->owed($landlord->id));

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'disbursement_awaiting_confirmation')
                ->exists()
        );
    }

    public function test_admin_can_backdate_a_disbursement_and_override_the_payment_method(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        Livewire::actingAs($admin)
            ->test(AdminDisbursementsIndex::class)
            ->call('startDisburse', $landlord->id)
            ->set('amount', '9000')
            ->set('disbursedAt', now()->subDays(3)->toDateString())
            ->set('destinationType', 'bank')
            ->set('destinationShortcode', 'ACC-998877')
            ->set('reference', 'BANK-REF-001')
            ->call('confirmDisburse')
            ->assertHasNoErrors();

        $disbursement = Disbursement::where('landlord_id', $landlord->id)->firstOrFail();
        $this->assertEquals(now()->subDays(3)->toDateString(), $disbursement->disbursed_at->toDateString());
        $this->assertEquals('bank', $disbursement->destination_type);
        $this->assertEquals('ACC-998877', $disbursement->destination_shortcode);
    }

    public function test_landlord_confirming_a_disbursement_updates_only_the_acknowledgement_status(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        $disbursement = app(DisbursementService::class)->recordDisbursement($landlord->id, 20000, 'REF-100', null, $admin->id);
        $owedBefore = app(DisbursementService::class)->owed($landlord->id);

        Livewire::actingAs($landlord)
            ->test(LandlordPayoutsIndex::class)
            ->call('confirm', $disbursement->id)
            ->assertHasNoErrors();

        $disbursement->refresh();
        $this->assertEquals('confirmed', $disbursement->status);
        $this->assertNotNull($disbursement->confirmed_at);
        $this->assertEquals($landlord->id, $disbursement->confirmed_by);

        // Confirming must not alter what's owed a second time.
        $this->assertEquals($owedBefore, app(DisbursementService::class)->owed($landlord->id));

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'disbursement_confirmed')
                ->exists()
        );
    }

    public function test_landlord_disputing_a_disbursement_requires_a_reason_and_notifies_admins(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        $disbursement = app(DisbursementService::class)->recordDisbursement($landlord->id, 15000, 'REF-200', null, $admin->id);

        // No reason — rejected, stays awaiting confirmation.
        Livewire::actingAs($landlord)
            ->test(LandlordPayoutsIndex::class)
            ->call('dispute', $disbursement->id, '');

        $this->assertEquals('awaiting_landlord_confirmation', $disbursement->fresh()->status);

        Livewire::actingAs($landlord)
            ->test(LandlordPayoutsIndex::class)
            ->call('dispute', $disbursement->id, 'Never received this transfer.')
            ->assertHasNoErrors();

        $disbursement->refresh();
        $this->assertEquals('disputed', $disbursement->status);
        $this->assertEquals('Never received this transfer.', $disbursement->dispute_reason);
        $this->assertEquals($landlord->id, $disbursement->disputed_by);

        // A dispute alone must not put the money back into "owed" — only an
        // admin's explicit reversal does, after investigating.
        $this->assertEquals(5000.0, app(DisbursementService::class)->owed($landlord->id));

        $this->assertTrue(
            AppNotification::where('notifiable_type', $admin->getMorphClass())
                ->where('notifiable_id', $admin->id)
                ->where('event_type', 'disbursement_disputed')
                ->exists()
        );
    }

    public function test_admin_reversing_a_disputed_disbursement_restores_the_owed_amount(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        $disbursement = app(DisbursementService::class)->recordDisbursement($landlord->id, 15000, 'REF-300', null, $admin->id);
        app(DisbursementService::class)->dispute($disbursement, $landlord, 'Wrong amount received.');

        $this->assertEquals(5000.0, app(DisbursementService::class)->owed($landlord->id));

        Livewire::actingAs($admin)
            ->test(AdminDisbursementsShow::class, ['landlord' => $landlord])
            ->call('reverseDisbursement', $disbursement->id, 'Confirmed with the bank — transfer never went out.')
            ->assertHasNoErrors();

        $disbursement->refresh();
        $this->assertEquals('reversed', $disbursement->status);
        $this->assertEquals($admin->id, $disbursement->reversed_by);
        $this->assertEquals(20000.0, app(DisbursementService::class)->owed($landlord->id));

        // The row is kept, never deleted — it's the audit trail for the correction.
        $this->assertDatabaseHas('disbursements', ['id' => $disbursement->id]);
    }

    public function test_admin_marking_a_disbursement_failed_restores_the_owed_amount(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 10000);

        $disbursement = app(DisbursementService::class)->recordDisbursement($landlord->id, 10000, 'REF-400', null, $admin->id);

        Livewire::actingAs($admin)
            ->test(AdminDisbursementsShow::class, ['landlord' => $landlord])
            ->call('markFailed', $disbursement->id, 'Bank rejected the transfer — wrong account number.')
            ->assertHasNoErrors();

        $disbursement->refresh();
        $this->assertEquals('failed', $disbursement->status);
        $this->assertEquals(10000.0, app(DisbursementService::class)->owed($landlord->id));
    }

    public function test_a_duplicate_reference_is_rejected(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        app(DisbursementService::class)->recordDisbursement($landlord->id, 5000, 'DUPLICATE-REF', null, $admin->id);

        $this->expectException(\InvalidArgumentException::class);
        app(DisbursementService::class)->recordDisbursement($landlord->id, 5000, 'DUPLICATE-REF', null, $admin->id);
    }

    public function test_partial_payouts_accumulate_and_cannot_exceed_what_is_owed(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        $service = app(DisbursementService::class);
        $service->recordDisbursement($landlord->id, 8000, 'PARTIAL-1', null, $admin->id);
        $this->assertEquals(12000.0, $service->owed($landlord->id));

        $service->recordDisbursement($landlord->id, 7000, 'PARTIAL-2', null, $admin->id);
        $this->assertEquals(5000.0, $service->owed($landlord->id));

        $this->expectException(\InvalidArgumentException::class);
        $service->recordDisbursement($landlord->id, 5001, 'PARTIAL-3', null, $admin->id);
    }

    public function test_owed_balance_aggregates_rent_collected_across_multiple_properties(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $properties = $landlord->properties()->get();
        $this->assertGreaterThan(1, $properties->count(), 'Seeded landlord is expected to have more than one property.');

        $this->createTenancyFor($landlord, $properties[0]);
        Payment::create([
            'tenancy_id' => $this->createTenancyFor($landlord, $properties[0])->id,
            'amount' => 10000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'mpesa',
            'collection_mode' => 'platform', 'recorded_by' => $landlord->id, 'source' => 'system_payment',
        ]);
        Payment::create([
            'tenancy_id' => $this->createTenancyFor($landlord, $properties[1])->id,
            'amount' => 15000, 'payment_date' => now()->format('Y-m-d'), 'method' => 'mpesa',
            'collection_mode' => 'platform', 'recorded_by' => $landlord->id, 'source' => 'system_payment',
        ]);

        $this->assertEquals(25000.0, app(DisbursementService::class)->owed($landlord->id));
    }

    public function test_a_landlord_cannot_act_on_another_landlords_disbursement(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        $disbursement = app(DisbursementService::class)->recordDisbursement($landlord->id, 10000, 'REF-500', null, $admin->id);

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($otherLandlord)
            ->test(LandlordPayoutsIndex::class)
            ->call('confirm', $disbursement->id);
    }

    public function test_a_manager_cannot_access_my_payouts(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $manager = \App\Models\Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Payout Test Manager',
            'phone' => '0700000099',
            'email' => 'payout-test-manager@example.test',
            'all_properties' => true,
            'status' => 'active',
            'token' => \App\Models\Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);

        $this->actingAs($managerUser->fresh())->get(route('landlord.payouts.index'))->assertForbidden();
    }

    public function test_payout_badge_counts_are_scoped_to_the_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $admin = User::role('admin')->firstOrFail();
        $this->createPlatformPayment($landlord, 20000);

        app(DisbursementService::class)->recordDisbursement($landlord->id, 10000, 'REF-600', null, $admin->id);

        $this->assertEquals(1, app(PendingActionsService::class)->payoutsAwaitingLandlordConfirmationCount($landlord));

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $this->assertEquals(0, app(PendingActionsService::class)->payoutsAwaitingLandlordConfirmationCount($otherLandlord));
    }
}
