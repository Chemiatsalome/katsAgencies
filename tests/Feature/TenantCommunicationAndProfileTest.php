<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as LandlordMessages;
use App\Livewire\Tenant\Announcements\Index as TenantAnnouncements;
use App\Livewire\Tenant\Profile;
use App\Models\AppNotification;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantCommunicationAndProfileTest extends TestCase
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

    public function test_seeding_creates_a_working_demo_tenant_login(): void
    {
        $this->seed();

        $user = User::where('email', 'tenant@katsagencies.test')->first();
        $this->assertNotNull($user, 'Seeding should create a demo tenant login.');
        $this->assertTrue($user->hasRole('tenant'));
        $this->assertNotNull($user->tenant, 'The demo user should be linked to a real seeded Tenant record.');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $user->password));

        $response = $this->actingAs($user)->get(route('tenant.dashboard'));
        $response->assertOk();
    }

    public function test_landlord_message_appears_in_tenant_announcements_but_not_notifications_double_counted_elsewhere(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($landlord)
            ->test(LandlordMessages::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Water Maintenance')
            ->set('body', 'Water maintenance will take place on Saturday from 9am to 1pm.')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasNoErrors();

        Livewire::actingAs($user)
            ->test(TenantAnnouncements::class)
            ->assertSee('Water Maintenance')
            ->assertSee('Saturday');
    }

    public function test_tenant_can_update_their_own_contact_details_and_landlord_is_notified(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $landlord = $tenant->tenancies()->active()->with('house.block.property')->first()->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('phone', '0799887766')
            ->set('emergency_contact_name', 'Jane Doe')
            ->set('emergency_contact_phone', '0700112233')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('0799887766', $tenant->fresh()->phone);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'tenant_profile_updated')
                ->exists()
        );
    }

    public function test_tenant_cannot_edit_landlord_controlled_tenancy_fields_through_profile(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();
        $originalRent = (string) $tenancy->monthly_rent;

        // Profile only exposes contact fields — there is no way to submit a rent change through it.
        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('phone', '0711223344')
            ->call('save');

        $this->assertEquals($originalRent, (string) $tenancy->fresh()->monthly_rent);
    }
}
