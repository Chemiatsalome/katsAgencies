<?php

namespace Tests\Feature;

use App\Livewire\Arrears\Index as ArrearsIndex;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\Rent as TenantRent;
use App\Models\House;
use App\Models\LateFeeWaiverRequest;
use App\Models\RentCharge;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2 of the tenant module audit. Covers one fix: accepting a late-fee
 * waiver request zeroes RentCharge.late_fee_amount (see
 * RentCharge::waiveLateFee()), which previously hid the ENTIRE waiver
 * section on My Rent — including the fact that a waiver had been granted
 * at all. A rejected request kept the fee (and the decline message)
 * visible; only the happier "accepted" outcome left no trace, unlike the
 * landlord's own Tenancies\Show page, which always keeps decided-waiver
 * history visible regardless of the charge's current fee amount.
 */
class TenantRentModuleTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): \App\Models\Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Rent Module Test Tenant',
            'phone' => '0700'.random_int(100000, 999999),
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(2)->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_an_accepted_waiver_shows_a_waived_confirmation_instead_of_disappearing(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'rent-module-waived@example.test', 'password'
        );

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);

        $waiverRequest = LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'pending',
        ]);

        // Mirrors Arrears\Index::acceptWaiverRequest() exactly: zero the fee
        // on the charge itself, then mark the request accepted.
        $charge->waiveLateFee($landlord->name);
        $waiverRequest->update(['status' => 'accepted', 'decided_by' => $landlord->id, 'decided_at' => now()]);

        $this->assertEquals(0, $charge->fresh()->late_fee_amount);

        Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->set('year', (int) now()->year)
            ->assertSee('Late fee waived')
            ->assertDontSee('Request Waiver');
    }

    public function test_a_current_late_fee_with_no_waiver_history_shows_the_request_button_not_a_waived_label(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'rent-module-unwaived@example.test', 'password'
        );

        RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);

        Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->set('year', (int) now()->year)
            ->assertSee('Request Waiver')
            ->assertDontSee('Late fee waived');
    }

    public function test_a_rejected_waiver_still_keeps_the_fee_and_decline_reason_visible(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'rent-module-rejected@example.test', 'password'
        );

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);

        LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'rejected', 'decided_at' => now()->subDay(), 'decision_note' => 'Already late once this year.',
        ]);

        Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->set('year', (int) now()->year)
            ->assertSee('Previous request declined')
            ->assertSee('Already late once this year')
            ->assertSee('Request Waiver')
            ->assertDontSee('Late fee waived');
    }

    /**
     * Requested verification #3: the confirmation must be a real, durable
     * read of the database — not Livewire component state that happens to
     * survive only within one test's object — so this exercises the actual
     * HTTP route/controller cycle (a genuine fresh page load) rather than
     * reusing the Livewire test harness instance from the accept action.
     */
    public function test_waived_confirmation_persists_across_a_fresh_http_page_load(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'rent-module-persist@example.test', 'password'
        );

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);
        $waiverRequest = LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'pending',
        ]);

        // Accept via the real landlord-side action in one request...
        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('acceptWaiverRequest', $waiverRequest->id);

        // ...then load the tenant's page as a brand new, unrelated HTTP
        // request — no shared component state, no cached query — and
        // confirm the confirmation is still there.
        $this->actingAs($tenantUser)
            ->get(route('tenant.rent', ['year' => now()->year]))
            ->assertOk()
            ->assertSee('Late fee waived');

        // And again, independently, after a second fresh request.
        $this->actingAs($tenantUser)
            ->get(route('tenant.rent', ['year' => now()->year]))
            ->assertOk()
            ->assertSee('Late fee waived');
    }

    /**
     * Requested verification #4: the tenant's My Rent page and the
     * landlord's own Tenancies\Show page must agree on the same waiver
     * decision — same status, same decision note where applicable, same
     * count in each side's own history view.
     */
    public function test_tenant_and_landlord_views_show_consistent_waiver_decisions(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'rent-module-consistency@example.test', 'password'
        );

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id, 'period_year' => now()->year, 'period_month' => now()->month,
            'due_date' => now(), 'amount_expected' => 20500, 'amount_paid' => 0, 'status' => 'overdue',
            'late_fee_amount' => 500,
        ]);

        $rejectedRequest = LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'pending',
        ]);

        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('rejectWaiverRequest', $rejectedRequest->id, 'Already late once this year.');

        // A second request on the same charge, later accepted.
        $acceptedRequest = LateFeeWaiverRequest::create([
            'rent_charge_id' => $charge->id, 'tenancy_id' => $tenancy->id, 'requested_amount' => 500,
            'status' => 'pending',
        ]);

        Livewire::actingAs($landlord)
            ->test(ArrearsIndex::class)
            ->call('acceptWaiverRequest', $acceptedRequest->id);

        // Tenant side: sees the fee as waived, and both past decisions in history.
        $tenantComponent = Livewire::actingAs($tenantUser)
            ->test(TenantRent::class)
            ->set('year', (int) now()->year);

        $tenantCharge = $tenantComponent->viewData('rentCharges')->firstWhere('id', $charge->id);
        $this->assertCount(2, $tenantCharge->waiverRequests);

        $tenantComponent
            ->assertSee('Late fee waived')
            ->assertSee('Show past requests (2)');

        // Landlord side: the exact same two decided requests, same note.
        $landlordComponent = Livewire::actingAs($landlord)->test(TenancyShow::class, ['tenancy' => $tenancy]);
        $landlordHistory = $landlordComponent->viewData('waiverRequestHistory');

        $this->assertCount(2, $landlordHistory);
        $this->assertTrue($landlordHistory->contains(fn ($r) => $r->status === 'accepted' && $r->id === $acceptedRequest->id));
        $this->assertTrue($landlordHistory->contains(fn ($r) => $r->status === 'rejected' && $r->decision_note === 'Already late once this year.'));

        $landlordComponent->call('toggleWaiverHistory')->assertSee('Already late once this year.');

        // Both sides must agree on the charge's final numbers too.
        $charge->refresh();
        $this->assertEquals(0, $charge->late_fee_amount);
        $this->assertEquals(20000.0, (float) $charge->amount_expected); // 20500 - 500 waived fee
    }
}
