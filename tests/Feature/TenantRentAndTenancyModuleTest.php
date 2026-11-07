<?php

namespace Tests\Feature;

use App\Livewire\Tenant\MyTenancy;
use App\Livewire\Tenant\Payments;
use App\Livewire\Tenant\Statements;
use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantRentAndTenancyModuleTest extends TestCase
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

    public function test_tenant_can_download_a_payment_receipt_and_a_full_statement(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'reference_number' => 'TEST123',
        ]);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(200);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadStatement')
            ->assertStatus(200);
    }

    public function test_tenant_cannot_download_a_receipt_for_an_opening_balance_entry(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now()->subMonths(2),
            'method' => 'other',
            'source' => 'opening_balance',
        ]);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(404);
    }

    public function test_opening_balance_payments_are_labeled_not_hidden_in_tenant_payment_lists(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now()->subMonths(2),
            'method' => 'other',
            'source' => 'opening_balance',
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Tenant\Payments::class)
            ->assertSee('Historical balance');
    }

    public function test_tenant_dashboard_profile_link_points_to_the_tenant_profile_page(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        $this->actingAs($user)
            ->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee(route('tenant.profile'), false);
    }

    public function test_tenant_cannot_download_another_tenants_payment_receipt(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $user->tenant->id)->firstOrFail();
        $otherPayment = Payment::create([
            'tenancy_id' => $otherTenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $otherPayment->id);
    }

    /**
     * Phase 5a audit fix (P5-C-01): the status badge on /tenant/payments
     * used to be hardcoded to "paid" for every row regardless of the
     * payment's actual status — an awaiting-confirmation or disputed claim
     * displayed identically to a landlord-confirmed one. Each status is
     * now checked separately below, including the "no non-confirmed
     * payment reads as paid" requirement via assertDontSee.
     */
    public function test_an_awaiting_confirmation_payment_shows_the_correct_status_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        // Seeded tenancies already carry their own confirmed Payment
        // history — cleared so this test's single controlled row is the
        // only one on the page, making whole-page assertSee/assertDontSee
        // assertions meaningful.
        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Awaiting Landlord Confirmation')
            ->assertDontSee('Paid')
            ->assertDontSee('Confirmed');
    }

    public function test_a_disputed_payment_shows_the_correct_status_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
            'status' => 'disputed',
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Disputed')
            ->assertDontSee('Paid')
            ->assertDontSee('Confirmed');
    }

    public function test_a_confirmed_payment_still_shows_a_successful_status_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'status' => 'confirmed',
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Confirmed')
            ->assertDontSee('Disputed')
            ->assertDontSee('Awaiting Landlord Confirmation');
    }

    /**
     * Phase 5a audit fix (P5-C-02): downloadReceipt() checked ownership and
     * opening-balance status but never the payment's own confirmation
     * status — a tenant could generate an official-looking receipt PDF for
     * their own still-unverified claim, or for a payment the landlord had
     * disputed. Enforced server-side (not just by hiding the button), via
     * the exact same needsLandlordConfirmation()/isDisputed() checks
     * already used to label these states correctly on Tenant\Rent and
     * Tenant\Deposit.
     */
    public function test_downloading_a_receipt_for_an_awaiting_confirmation_payment_is_blocked(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        // Matches the established pattern for this exact abort_if() guard
        // (see test_tenant_cannot_download_a_receipt_for_an_opening_balance_entry
        // above) — Livewire's test harness surfaces abort_if()'s
        // NotFoundHttpException as a 404 response, not a raised exception.
        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(404);
    }

    public function test_downloading_a_receipt_for_a_disputed_payment_is_blocked(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
            'status' => 'disputed',
        ]);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(404);
    }

    public function test_the_download_receipt_button_is_hidden_for_awaiting_confirmation_and_disputed_payments(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        // Isolates this assertion to exactly the two controlled rows below
        // — the seeded tenancy already has its own confirmed payments,
        // which legitimately still show the button and would otherwise
        // make a whole-page "does not contain" assertion meaningless.
        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 4000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
            'reference_number' => 'AWAITING-REF-1',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 6000,
            'payment_date' => now(),
            'method' => 'cash',
            'status' => 'disputed',
            'reference_number' => 'DISPUTED-REF-1',
        ]);

        $html = Livewire::actingAs($user)
            ->test(Statements::class)
            ->html();

        $this->assertStringNotContainsString('Download Receipt', $html);
    }

    public function test_downloading_a_receipt_for_a_confirmed_payment_still_succeeds(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'status' => 'confirmed',
            'reference_number' => 'CONFIRMED-REF-1',
        ]);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(200);

        $html = Livewire::actingAs($user)
            ->test(Statements::class)
            ->html();

        $this->assertStringContainsString('Download Receipt', $html);
    }

    /**
     * Phase 5b (P5-H-01): the Payments page showed a bare status badge with
     * no detail — a disputed payment gave the tenant no idea why, or what
     * to do about it, unlike the identical case already handled correctly
     * on My Rent (rent.blade.php) and My Deposit.
     */
    public function test_a_disputed_payment_shows_its_dispute_reason_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
            'status' => 'disputed',
            'dispute_reason' => 'Reference number does not match any transaction on our statement.',
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Disputed')
            ->assertSee('Reference number does not match any transaction on our statement.');
    }

    public function test_an_awaiting_confirmation_payment_shows_a_clear_note_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Awaiting Landlord Confirmation')
            // escape: false — this line is static template text, not an
            // echoed value, so the rendered HTML carries a literal
            // apostrophe rather than Blade's usual &#039; escaping.
            ->assertSee("Awaiting your landlord's confirmation.", false);
    }

    public function test_a_landlord_confirmed_payment_shows_its_confirmation_date_on_the_payments_page(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
        ]);

        Livewire::actingAs($user)
            ->test(Payments::class)
            ->assertSee('Confirmed '.now()->subDay()->format('d M Y'));
    }

    /**
     * Phase 5b (P5-H-02): the downloadable statement's Payment History table
     * listed every payment with no status at all, directly beside an
     * Outstanding Balance figure that (correctly) excludes anything not yet
     * confirmed — a tenant reading the PDF alone had no way to reconcile
     * the two. Rendered directly from the view (bypassing DomPDF itself,
     * a third-party rendering engine, not something this test needs to
     * exercise) so the template's own logic is what's actually checked.
     */
    public function test_the_downloadable_statement_includes_payment_status_and_dispute_information(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::where('tenancy_id', $tenancy->id)->delete();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
            'status' => 'disputed',
            'dispute_reason' => 'Amount does not match.',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 3000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
        ]);
        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 8000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'status' => 'confirmed',
        ]);

        $html = view('tenant.statement', [
            'tenant' => $user->tenant,
            'tenancy' => $tenancy,
            'rentCharges' => $tenancy->rentCharges,
            'payments' => $tenancy->payments()->orderBy('payment_date')->get(),
        ])->render();

        $this->assertStringContainsString('Disputed — Amount does not match.', $html);
        $this->assertStringContainsString('Awaiting landlord confirmation', $html);
        $this->assertStringContainsString('Confirmed', $html);
    }

    public function test_tenant_can_request_a_tenancy_change_and_landlord_is_notified(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(MyTenancy::class)
            ->call('openRequestChangeForm')
            ->set('change_category', 'other')
            ->set('change_requested_change', 'My phone number on file is outdated.')
            ->call('submitChangeRequest')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenancy_change_requests', [
            'tenancy_id' => $tenancy->id,
            'category' => 'other',
        ]);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'tenancy_change_request_submitted')
                ->exists()
        );
    }

    public function test_tenant_tenancy_history_shows_past_tenancies_but_not_the_current_one_twice(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $currentTenancy = $tenant->tenancies()->active()->firstOrFail();

        $vacantHouse = \App\Models\House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $currentTenancy->house->block->property->landlord_id))
            ->first();

        if ($vacantHouse) {
            Tenancy::create([
                'tenant_id' => $tenant->id,
                'house_id' => $vacantHouse->id,
                'move_in_date' => now()->subYears(2),
                'move_out_date' => now()->subYear(),
                'monthly_rent' => 5000,
                'rent_due_day' => 1,
                'status' => 'completed',
            ]);
        }

        $component = Livewire::actingAs($user)->test(MyTenancy::class);

        if ($vacantHouse) {
            $component->assertSee('Previous Tenancies');
        }
        $component->assertSee($currentTenancy->house->house_number);
    }
}
