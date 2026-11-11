<?php

namespace Tests\Feature;

use App\Livewire\TenantInvitations\Accept;
use App\Livewire\TenantInvitations\Create;
use App\Livewire\TenantInvitations\Index as InvitationIndex;
use App\Models\House;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantInvitationModuleTest extends TestCase
{
    use RefreshDatabase;

    private function vacantHouseFor(User $landlord): House
    {
        $house = House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->first();

        $this->assertNotNull($house, 'Seed data should include at least one vacant unit for this landlord.');

        return $house;
    }

    public function test_landlord_can_generate_an_invitation_and_it_reserves_the_unit(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        Livewire::actingAs($landlord)
            ->test(Create::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('monthly_rent', '12000')
            ->set('deposit_required', '12000')
            ->set('rent_due_day', 5)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertEquals('reserved', $house->fresh()->status);
        $this->assertDatabaseHas('tenant_invitations', [
            'house_id' => $house->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Regression/feature test: Invite Tenant was rebuilt to adopt Add
     * Tenant's "Deposit Paid" preset+summary UI, backed by two new columns
     * on tenant_invitations (initial_deposit_paid, deposit_method) that
     * flow straight into TenancyProvisioningService's existing
     * DepositPayment-creation branch once the invitation is accepted —
     * covering the zero/full/partial scenarios the deposit UI offers.
     */
    public function test_a_deposit_already_collected_before_inviting_a_tenant_flows_through_to_the_resulting_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        Livewire::actingAs($landlord)
            ->test(Create::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('monthly_rent', '20000')
            ->set('deposit_required', '20000')
            ->set('rent_due_day', 5)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('deposit_option', 'full')
            ->set('deposit_method', 'mpesa')
            ->call('generate')
            ->assertHasNoErrors();

        $invitation = TenantInvitation::where('house_id', $house->id)->firstOrFail();
        $this->assertEquals(20000, (float) $invitation->initial_deposit_paid);
        $this->assertEquals('mpesa', $invitation->deposit_method);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'Deposit Already Paid')
            ->set('phone', '0711000222')
            ->set('email', 'deposit.already.paid@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $tenant = Tenant::where('email', 'deposit.already.paid@example.test')->firstOrFail();
        $tenancy = $tenant->tenancies()->firstOrFail();

        $this->assertDatabaseHas('deposit_payments', [
            'tenancy_id' => $tenancy->id,
            'amount' => 20000,
            'method' => 'mpesa',
            'recorded_by' => $landlord->id,
        ]);
    }

    public function test_no_deposit_payment_is_recorded_when_nothing_was_collected_before_the_invitation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        Livewire::actingAs($landlord)
            ->test(Create::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('monthly_rent', '15000')
            ->set('deposit_required', '15000')
            ->set('rent_due_day', 5)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->call('generate')
            ->assertHasNoErrors();

        $invitation = TenantInvitation::where('house_id', $house->id)->firstOrFail();
        $this->assertEquals(0, (float) $invitation->initial_deposit_paid);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'No Deposit Yet')
            ->set('phone', '0711000333')
            ->set('email', 'no.deposit.yet@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $tenant = Tenant::where('email', 'no.deposit.yet@example.test')->firstOrFail();
        $tenancy = $tenant->tenancies()->firstOrFail();

        $this->assertDatabaseMissing('deposit_payments', ['tenancy_id' => $tenancy->id]);
    }

    public function test_a_partial_deposit_paid_before_inviting_a_tenant_flows_through_to_the_resulting_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        Livewire::actingAs($landlord)
            ->test(Create::class)
            ->set('property_id', $house->block->property_id)
            ->set('block_id', $house->block_id)
            ->set('house_id', $house->id)
            ->set('monthly_rent', '18000')
            ->set('deposit_required', '18000')
            ->set('rent_due_day', 5)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->set('deposit_option', 'custom')
            ->set('initial_deposit_paid', '5000')
            ->set('deposit_method', 'cash')
            ->call('generate')
            ->assertHasNoErrors();

        $invitation = TenantInvitation::where('house_id', $house->id)->firstOrFail();
        $this->assertEquals(5000, (float) $invitation->initial_deposit_paid);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'Partial Deposit Paid')
            ->set('phone', '0711000444')
            ->set('email', 'partial.deposit.paid@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $tenant = Tenant::where('email', 'partial.deposit.paid@example.test')->firstOrFail();
        $tenancy = $tenant->tenancies()->firstOrFail();

        $this->assertDatabaseHas('deposit_payments', [
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'method' => 'cash',
            'recorded_by' => $landlord->id,
        ]);
    }

    public function test_new_tenant_can_self_register_through_an_invitation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 14000,
            'deposit_required' => 14000,
            'rent_due_day' => 5,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
        $house->update(['status' => 'reserved']);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'Jane Registrant')
            ->set('phone', '0711000111')
            ->set('email', 'jane.registrant@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $invitation->refresh();
        $this->assertEquals('completed', $invitation->status);
        $this->assertNotNull($invitation->resulting_tenancy_id);
        $this->assertEquals('occupied', $house->fresh()->status);

        $tenant = Tenant::where('email', 'jane.registrant@example.test')->firstOrFail();
        $this->assertNotNull($tenant->user_id);
        $this->assertTrue($tenant->user->hasRole('tenant'));

        $tenancy = $tenant->tenancies()->firstOrFail();
        $this->assertEquals($house->id, $tenancy->house_id);
        $this->assertEquals('active', $tenancy->status);
        $this->assertEquals(14000, (float) $tenancy->monthly_rent);
    }

    /** Optional business name (part D of the property-category work), captured on self-registration through an invitation — never required. */
    public function test_a_tenant_can_record_an_optional_business_name_while_self_registering(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 18000,
            'deposit_required' => 18000,
            'rent_due_day' => 1,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->set('name', 'Stall Vendor')
            ->set('business_name', 'Fresh Produce Stall')
            ->set('phone', '0711000555')
            ->set('email', 'fresh.produce.stall@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', ['name' => 'Stall Vendor', 'business_name' => 'Fresh Produce Stall']);
    }

    public function test_existing_tenant_can_log_in_and_accept_a_second_tenancy_preserving_history(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $existingTenant = Tenant::whereNotNull('user_id')->first()
            ?? tap(Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail(), function (Tenant $tenant) {
                $user = User::factory()->create(['password' => bcrypt('password123')]);
                $user->syncRoles(['tenant']);
                $tenant->update(['user_id' => $user->id]);
            });

        $existingTenant->user->update(['password' => bcrypt('password123')]);
        $priorTenancyCount = $existingTenant->tenancies()->count();

        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'existing_tenant_id' => $existingTenant->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 9000,
            'deposit_required' => 9000,
            'rent_due_day' => 1,
            'move_in_date' => now()->addMonth()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
        $house->update(['status' => 'reserved']);

        Livewire::test(Accept::class, ['token' => $invitation->token])
            ->call('switchToLogin')
            ->set('login_email', $existingTenant->user->email)
            ->set('login_password', 'password123')
            ->call('logInAndAccept')
            ->assertHasNoErrors();

        $existingTenant->refresh();
        $this->assertEquals($priorTenancyCount + 1, $existingTenant->tenancies()->count());
        $this->assertEquals('occupied', $house->fresh()->status);
    }

    public function test_expired_or_cancelled_invitation_cannot_be_accepted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 5000,
            'deposit_required' => 5000,
            'rent_due_day' => 1,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertFalse($invitation->isUsable());

        $component = Livewire::test(Accept::class, ['token' => $invitation->token]);
        $component->assertSee('cancelled');

        // A crafted direct call must also be rejected server-side, not just hidden in the UI.
        $component->set('name', 'Should Not Work')
            ->set('phone', '0700000000')
            ->set('email', 'should-not-work@example.test')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertDatabaseMissing('tenants', ['email' => 'should-not-work@example.test']);
    }

    public function test_landlord_can_cancel_and_regenerate_an_invitation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = $this->vacantHouseFor($landlord);

        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 7000,
            'deposit_required' => 7000,
            'rent_due_day' => 1,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
        $house->update(['status' => 'reserved']);

        Livewire::actingAs($landlord)->test(InvitationIndex::class)->call('cancel', $invitation->id);

        $invitation->refresh();
        $this->assertEquals('cancelled', $invitation->status);
        $this->assertEquals('vacant', $house->fresh()->status);

        $oldToken = $invitation->token;
        Livewire::actingAs($landlord)->test(InvitationIndex::class)->call('regenerate', $invitation->id);

        $invitation->refresh();
        $this->assertEquals('pending', $invitation->status);
        $this->assertNotEquals($oldToken, $invitation->token);
    }

    public function test_landlord_cannot_manage_another_landlords_invitation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);

        $house = $this->vacantHouseFor($landlord);
        $invitation = TenantInvitation::create([
            'house_id' => $house->id,
            'invited_by' => $landlord->id,
            'token' => TenantInvitation::generateToken(),
            'monthly_rent' => 7000,
            'deposit_required' => 7000,
            'rent_due_day' => 1,
            'move_in_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Livewire::actingAs($otherLandlord)
            ->test(InvitationIndex::class)
            ->call('cancel', $invitation->id)
            ->assertForbidden();

        $this->assertEquals('pending', $invitation->fresh()->status);
    }
}
