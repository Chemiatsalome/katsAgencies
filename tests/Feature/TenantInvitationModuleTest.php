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
            ->call('goToStep2')
            ->assertSet('step', 2)
            ->set('monthly_rent', '12000')
            ->set('deposit_required', '12000')
            ->set('rent_due_day', 5)
            ->set('move_in_date', now()->format('Y-m-d'))
            ->call('generate')
            ->assertSet('step', 3)
            ->assertHasNoErrors();

        $this->assertEquals('reserved', $house->fresh()->status);
        $this->assertDatabaseHas('tenant_invitations', [
            'house_id' => $house->id,
            'status' => 'pending',
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
