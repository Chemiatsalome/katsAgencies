<?php

namespace Tests\Feature;

use App\Livewire\Tenant\Dashboard;
use App\Livewire\Tenant\Deposit;
use App\Livewire\Tenant\Maintenance\Show as MaintenanceShow;
use App\Models\Complaint;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1 of the tenant module audit (see Phase 0 findings T-5/dashboard
 * instance and the "maintenance requests" banner-vs-content gap). Covers
 * three fixes made directly to the Dashboard component/view: unconfirmed
 * and disputed payments are no longer indistinguishable from confirmed ones
 * in "Recent Payments"; a maintenance-requests summary now exists at all;
 * and a tenant whose tenancy has ended sees a relevant message instead of
 * the generic "no tenancy, contact someone" wall meant for someone who was
 * never assigned one.
 */
class TenantDashboardModuleTest extends TestCase
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

    public function test_an_unconfirmed_payment_is_annotated_not_shown_as_a_plain_payment(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'awaiting_landlord_confirmation',
            'source' => 'tenant_submitted_offline',
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('awaiting confirmation');
    }

    public function test_a_disputed_payment_is_annotated_on_the_dashboard(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'bank',
            'status' => 'disputed',
            'source' => 'tenant_submitted_offline',
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('disputed');
    }

    public function test_a_confirmed_payment_shows_with_no_extra_annotation(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'mpesa',
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertDontSee('awaiting confirmation')
            ->assertDontSee('disputed');
    }

    public function test_dashboard_shows_recent_maintenance_requests(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $complaint = Complaint::create([
            'tenant_id' => $user->tenant->id,
            'house_id' => $tenancy->house_id,
            'property_id' => $tenancy->house->block->property_id,
            'tenancy_id' => $tenancy->id,
            'category' => 'plumbing',
            'subject' => 'Leaking kitchen tap',
            'description' => 'Drips constantly.',
            'priority' => 'medium',
            'status' => 'submitted',
            'submitted_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Leaking kitchen tap')
            ->assertSee('Maintenance Requests');
    }

    public function test_dashboard_shows_an_empty_state_when_no_maintenance_requests_exist(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('No maintenance requests yet.');
    }

    public function test_a_tenant_whose_tenancy_ended_sees_a_deposit_link_not_the_contact_admin_message(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $tenancy->update([
            'status' => 'completed',
            'move_out_date' => now()->subDays(5)->toDateString(),
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Former Home')
            ->assertSee('Track your deposit refund')
            ->assertDontSee('contact your landlord or the admin');
    }

    public function test_a_tenant_with_no_tenancy_at_all_still_sees_the_original_contact_message(): void
    {
        $this->seed();

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        \App\Models\Tenant::create([
            'user_id' => $user->id,
            'name' => 'No Tenancy Tenant',
            'phone' => '0700'.random_int(100000, 999999),
            'payment_due_day' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('No active tenancy is linked to your account yet');
    }

    /**
     * Requested verification #1: recentComplaints must never surface
     * another tenant's request, and the maintenance.show route itself
     * (not just the policy in isolation) must independently reject an
     * attempt to open one by changing the ID in the URL.
     */
    public function test_recent_complaints_on_dashboard_never_include_another_tenants_complaint(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $user->tenant->id)->with('house.block.property')->firstOrFail();

        $otherComplaint = Complaint::create([
            'tenant_id' => $otherTenancy->tenant_id,
            'house_id' => $otherTenancy->house_id,
            'property_id' => $otherTenancy->house->block->property_id,
            'tenancy_id' => $otherTenancy->id,
            'category' => 'electrical',
            'subject' => 'Someone elses broken socket',
            'description' => 'Belongs to a different tenant entirely.',
            'priority' => 'medium',
            'status' => 'submitted',
            'submitted_by' => $otherTenancy->tenant->user_id ?? $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertDontSee('Someone elses broken socket');

        // The underlying query itself, not just the rendered HTML.
        $this->assertFalse(
            $user->tenant->complaints()->whereKey($otherComplaint->id)->exists()
        );
    }

    public function test_a_tenant_cannot_open_another_tenants_maintenance_request_via_the_show_route(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $user->tenant->id)->with('house.block.property')->firstOrFail();

        $otherComplaint = Complaint::create([
            'tenant_id' => $otherTenancy->tenant_id,
            'house_id' => $otherTenancy->house_id,
            'property_id' => $otherTenancy->house->block->property_id,
            'tenancy_id' => $otherTenancy->id,
            'category' => 'electrical',
            'subject' => 'Not this tenants problem',
            'description' => 'Belongs to a different tenant entirely.',
            'priority' => 'medium',
            'status' => 'submitted',
            'submitted_by' => $otherTenancy->tenant->user_id ?? $user->id,
        ]);

        // The route itself — not the policy assertion in isolation — must
        // reject this; changing the ID in the URL must not work.
        $this->actingAs($user)
            ->get(route('tenant.maintenance.show', $otherComplaint))
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(MaintenanceShow::class, ['complaint' => $otherComplaint])
            ->assertForbidden();
    }

    /**
     * Requested verification #2: with more than one historical tenancy,
     * mostRecentTenancy() must pick the one with the later move_in_date
     * (not just whichever row happens to be created last), and the
     * deposit-refund link from the Dashboard must land on a deposit page
     * reporting that SAME tenancy's figures — not an older one's.
     */
    public function test_former_home_panel_and_deposit_link_both_resolve_to_the_same_most_recent_historical_tenancy(): void
    {
        $this->seed();
        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $house = $tenant->currentTenancy()->house;

        // Older stay: ended first, fully refunded — deliberately distinct
        // figures (15,000 across the board) from the newer stay below, so
        // any leakage between the two is unambiguous in the assertions.
        $olderTenancy = Tenancy::create([
            'tenant_id' => $tenant->id,
            'house_id' => $house->id,
            'move_in_date' => now()->subYears(3)->toDateString(),
            'move_out_date' => now()->subYears(2)->toDateString(),
            'monthly_rent' => 15000,
            'deposit_required' => 15000,
            'deposit_paid' => 15000,
            'rent_due_day' => 1,
            'status' => 'completed',
        ]);
        \App\Models\DepositRefund::create([
            'tenancy_id' => $olderTenancy->id,
            'amount' => 15000,
            'refund_date' => now()->subYears(2)->toDateString(),
            'recorded_by' => $user->id,
        ]);

        // Deactivate the seeded active tenancy so it doesn't shadow either
        // historical row as "current".
        $tenant->currentTenancy()->update(['status' => 'completed', 'move_out_date' => now()->subMonths(8)->toDateString()]);

        // Newer stay: the one that should actually be picked as "most
        // recent" — only partially paid in, nothing refunded yet. Figures
        // (18,000 / 10,000 / 8,000 outstanding / 10,000 held) share no
        // value with the older tenancy's 15,000 across the board.
        $newerTenancy = Tenancy::create([
            'tenant_id' => $tenant->id,
            'house_id' => $house->id,
            'move_in_date' => now()->subMonths(6)->toDateString(),
            'move_out_date' => now()->subMonth()->toDateString(),
            'monthly_rent' => 18000,
            'deposit_required' => 18000,
            'deposit_paid' => 10000,
            'rent_due_day' => 1,
            'status' => 'completed',
        ]);

        $resolved = $tenant->mostRecentTenancy();
        $this->assertSame($newerTenancy->id, $resolved->id, 'mostRecentTenancy() must pick the later move_in_date, not the older stay.');

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Former Home')
            ->assertSee($house->house_number);

        // The deposit page the Dashboard's link points to must independently
        // resolve to the exact same tenancy, not the older, already-settled one.
        Livewire::actingAs($user)
            ->test(Deposit::class)
            ->assertSee('18,000.00') // required deposit, unique to the newer tenancy
            ->assertSee('10,000.00') // paid / currently held, unique to the newer tenancy
            ->assertSee('8,000.00')  // outstanding, unique to the newer tenancy
            ->assertDontSee('15,000.00'); // the older tenancy's figures must not leak in
    }
}
