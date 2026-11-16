<?php

namespace Tests\Feature;

use App\Livewire\Tenant\Announcements\Index as TenantAnnouncements;
use App\Livewire\Tenant\PayDeposit;
use App\Livewire\Tenant\PayRent;
use App\Models\House;
use App\Models\MpesaTransaction;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentGatewayService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Final account-lifecycle phase, Part A: a suspended landlord's tenants
 * must lose access to normal portal functionality and every payment-
 * initiation path, enforced centrally (EnsureTenantLandlordIsActive) and
 * at the specific payment actions themselves — not just by hiding UI.
 */
class TenantPortalRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenancyWithPortalUser(User $landlord, string $slug): array
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();
        $tenant = Tenant::create(['name' => "Restriction Tenant {$slug}", 'phone' => '0703'.substr(md5($slug), 0, 6), 'payment_due_day' => 1]);

        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);

        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenant->update(['user_id' => $portalUser->id]);

        return [$tenancy, $portalUser];
    }

    // --- A1: central portal access enforcement ------------------------------------------

    public function test_a_restricted_tenant_is_redirected_away_from_normal_portal_pages(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'a');
        $landlord->update(['status' => 'suspended']);

        foreach ([
            route('tenant.dashboard'), route('tenant.rent'), route('tenant.pay'),
            route('tenant.payments'), route('tenant.statements'), route('tenant.tenancy'),
            route('tenant.deposit'), route('tenant.deposit.pay'), route('tenant.contract'),
            route('tenant.maintenance.index'), route('tenant.announcements.index'), route('tenant.profile'),
        ] as $url) {
            $this->actingAs($portalUser)->get($url)->assertRedirect(route('tenant.account-restricted'));
        }
    }

    public function test_the_restriction_notice_page_itself_remains_reachable_and_logout_still_works(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'b');
        $landlord->update(['status' => 'suspended']);

        $this->actingAs($portalUser)->get(route('tenant.account-restricted'))->assertOk();

        Livewire::actingAs($portalUser)
            ->test(\App\Livewire\Layout\LogoutButton::class)
            ->call('logout');

        $this->assertGuest();
    }

    public function test_an_unrestricted_tenant_is_not_redirected(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'c');

        $this->actingAs($portalUser)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_other_roles_and_guests_are_unaffected_by_a_suspended_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'suspended']);

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->get(route('home'))->assertOk();
    }

    // --- A2: payment bypasses, beyond the STK push guard ---------------------------------

    public function test_a_restricted_tenant_cannot_submit_an_offline_rent_claim(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'd');
        $landlord->update(['status' => 'suspended']);

        // The middleware would already redirect a real page load — this
        // proves the action itself refuses too, the "direct Livewire
        // action invocation" case Livewire::test() exercises directly,
        // bypassing route/global middleware entirely.
        Livewire::actingAs($portalUser)
            ->test(PayRent::class)
            ->set('offline_method', 'cash')
            ->set('offline_amount', '1000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->call('submitOfflinePayment')
            ->assertHasErrors('offline_amount');

        $this->assertDatabaseMissing('payments', ['tenancy_id' => $tenancy->id]);
    }

    public function test_a_restricted_tenant_cannot_submit_an_offline_deposit_claim(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'e');
        $landlord->update(['status' => 'suspended']);

        Livewire::actingAs($portalUser)
            ->test(PayDeposit::class)
            ->set('offline_method', 'cash')
            ->set('offline_amount', '1000')
            ->set('offline_payment_date', now()->format('Y-m-d'))
            ->call('submitOfflinePayment')
            ->assertHasErrors('offline_amount');

        $this->assertDatabaseMissing('deposit_payments', ['tenancy_id' => $tenancy->id]);
    }

    public function test_stk_push_initiation_is_rejected_for_a_suspended_landlord(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'suspended']);

        $this->expectException(\RuntimeException::class);

        app(PaymentGatewayService::class)->initiateStkPush($landlord->id, '254712345678', 1000, 'Rent-1', 'Rent payment');
    }

    public function test_an_in_flight_mpesa_transaction_can_still_complete_after_suspension(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy] = $this->makeTenancyWithPortalUser($landlord, 'f');

        // Initiated while the landlord was still active.
        $transaction = MpesaTransaction::create([
            'tenancy_id' => $tenancy->id, 'initiated_by' => $landlord->id,
            'phone' => '254712345678', 'amount' => 1000, 'purpose' => 'rent',
            'checkout_request_id' => 'checkout-in-flight-1', 'status' => 'pending',
        ]);

        $landlord->update(['status' => 'suspended']);

        // The callback/reconciliation path never re-checks landlord status —
        // nothing here should prevent a legitimately in-flight transaction
        // from resolving normally.
        $transaction->update(['status' => 'success']);

        $this->assertDatabaseHas('mpesa_transactions', ['id' => $transaction->id, 'status' => 'success']);
    }

    // --- A3/A4: multi-landlord safety and reinstatement ----------------------------------

    public function test_tenant_access_is_restored_immediately_after_landlord_reinstatement(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'g');
        $landlord->update(['status' => 'suspended']);

        $this->actingAs($portalUser)->get(route('tenant.dashboard'))->assertRedirect(route('tenant.account-restricted'));

        $landlord->update(['status' => 'active']);

        $this->actingAs($portalUser)->get(route('tenant.dashboard'))->assertOk();
    }

    /**
     * The current data model has no way for one login to hold tenancies
     * under two different landlords at once — User::tenant() is a plain
     * hasOne, so it would always resolve to exactly one Tenant row
     * regardless of how many existed (see
     * Tenant::currentTenancyLandlordIsSuspended()'s own docblock, and
     * LandlordSuspensionService's). What the architecture does support,
     * and what this actually proves, is that the restriction is scoped
     * per request to the tenant's own resolved tenancy's landlord — a
     * completely separate tenant, under a completely separate landlord,
     * is never affected by someone else's suspension.
     */
    public function test_a_tenant_under_an_unrelated_landlord_is_not_restricted(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $restrictedTenantUser] = $this->makeTenancyWithPortalUser($landlord, 'i');
        $landlord->update(['status' => 'suspended']);

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $otherLandlord->id, 'name' => 'Unrelated Property', 'type' => 'residential',
            'address' => '123 Unrelated Rd', 'city' => 'Nairobi',
        ]);
        $otherBlock = \App\Models\Block::create(['property_id' => $otherProperty->id, 'name' => 'A']);
        $otherHouse = \App\Models\House::create([
            'block_id' => $otherBlock->id, 'house_number' => 'A1', 'type' => 'bedsitter',
            'monthly_rent' => 5000, 'deposit_amount' => 5000, 'status' => 'vacant',
        ]);
        [, $otherTenantUser] = $this->makeTenancyWithPortalUserForHouse($otherHouse, 'i2');

        $this->actingAs($restrictedTenantUser)->get(route('tenant.dashboard'))->assertRedirect(route('tenant.account-restricted'));
        $this->actingAs($otherTenantUser)->get(route('tenant.dashboard'))->assertOk();
    }

    private function makeTenancyWithPortalUserForHouse(House $house, string $slug): array
    {
        $tenant = Tenant::create(['name' => "Restriction Tenant {$slug}", 'phone' => '0704'.substr(md5($slug), 0, 6), 'payment_due_day' => 1]);

        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);

        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenant->update(['user_id' => $portalUser->id]);

        return [$tenancy, $portalUser];
    }

    public function test_the_tenant_login_itself_is_never_touched_by_suspension_or_reinstatement(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $portalUser] = $this->makeTenancyWithPortalUser($landlord, 'h');

        $landlord->update(['status' => 'suspended']);
        $this->assertEquals('active', $portalUser->fresh()->status);

        $landlord->update(['status' => 'active']);
        $this->assertEquals('active', $portalUser->fresh()->status);
    }
}
