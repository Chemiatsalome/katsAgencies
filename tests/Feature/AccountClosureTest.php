<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Tenant\Profile as TenantProfile;
use App\Models\DepositDeduction;
use App\Models\DepositPayment;
use App\Models\House;
use App\Models\Manager;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 (account lifecycle), points E/F: tenant and landlord account
 * closure are both revoke-login-only operations, blocked outright while
 * genuinely active or unresolved business exists.
 */
class AccountClosureTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenancyFor(User $landlord, string $slug): Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();
        $tenant = Tenant::create(['name' => "Closure Tenant {$slug}", 'phone' => '0702'.substr(md5($slug), 0, 6), 'payment_due_day' => 1]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);
    }

    // --- Point E: tenant closure --------------------------------------------------------

    public function test_a_tenant_cannot_close_their_account_with_an_active_tenancy(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'a');
        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenancy->tenant->update(['user_id' => $portalUser->id]);

        Livewire::actingAs($portalUser)
            ->test(TenantProfile::class)
            ->call('closeAccount')
            ->assertSet('closureBlockers', fn ($blockers) => count($blockers) > 0);

        $this->assertEquals('active', $portalUser->fresh()->status);
    }

    public function test_a_tenant_cannot_close_their_account_with_a_held_deposit(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'b');
        $tenancy->forceFill(['status' => 'terminated', 'deposit_paid' => 5000])->save();
        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenancy->tenant->update(['user_id' => $portalUser->id]);

        Livewire::actingAs($portalUser)
            ->test(TenantProfile::class)
            ->call('closeAccount');

        $this->assertEquals('active', $portalUser->fresh()->status);
    }

    public function test_a_tenant_cannot_close_their_account_with_a_pending_payment(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'c');
        $tenancy->forceFill(['status' => 'terminated'])->save();

        Payment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 1000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'mpesa', 'status' => 'awaiting_landlord_confirmation',
        ]);

        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenancy->tenant->update(['user_id' => $portalUser->id]);

        Livewire::actingAs($portalUser)
            ->test(TenantProfile::class)
            ->call('closeAccount');

        $this->assertEquals('active', $portalUser->fresh()->status);
    }

    public function test_a_tenant_with_nothing_outstanding_can_close_their_account_and_history_is_preserved(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'd');
        $tenancy->forceFill(['status' => 'terminated'])->save();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id, 'amount' => 1000, 'payment_date' => now()->format('Y-m-d'),
            'method' => 'cash', 'status' => 'confirmed',
        ]);

        $portalUser = User::factory()->create(['status' => 'active']);
        $portalUser->assignRole('tenant');
        $tenant = $tenancy->tenant;
        $tenant->update(['user_id' => $portalUser->id]);

        Livewire::actingAs($portalUser)
            ->test(TenantProfile::class)
            ->call('closeAccount')
            ->assertSet('closureBlockers', []);

        $this->assertEquals('inactive', $portalUser->fresh()->status);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'user_id' => $portalUser->id]);
        $this->assertDatabaseHas('tenancies', ['id' => $tenancy->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    // --- Point F: landlord closure -------------------------------------------------------

    public function test_a_landlord_cannot_close_their_account_with_an_active_tenancy(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $this->makeTenancyFor($landlord, 'e');

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->call('closeAccount')
            ->assertSet('closureBlockers', fn ($blockers) => count($blockers) > 0);

        $this->assertEquals('active', $landlord->fresh()->status);
    }

    public function test_a_landlord_cannot_close_their_account_with_an_un_removed_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        Manager::create([
            'landlord_id' => $landlord->id, 'name' => 'Closure Test Manager',
            'phone' => '0712399999', 'email' => 'closure-test-manager@example.test',
            'all_properties' => true, 'status' => 'inactive',
            'token' => Manager::generateToken(), 'expires_at' => now()->addDays(7),
            'invited_at' => now(), 'activated_at' => now(),
        ]);

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->call('closeAccount');

        $this->assertEquals('active', $landlord->fresh()->status);
    }

    public function test_a_landlord_with_nothing_outstanding_can_close_their_account_and_properties_are_preserved(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        // Clear the seed's own fixtures so this is a genuine "nothing
        // outstanding" case, not an accidental false negative from
        // whatever the seeder happens to create.
        Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->get()->each(fn (Tenancy $t) => $t->forceFill(['status' => 'terminated', 'deposit_required' => 0, 'deposit_paid' => 0])->save());
        Manager::where('landlord_id', $landlord->id)->delete();

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->call('closeAccount')
            ->assertSet('closureBlockers', []);

        $this->assertEquals('inactive', $landlord->fresh()->status);
        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }
}
