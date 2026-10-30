<?php

namespace Tests\Feature;

use App\Livewire\Admin\Landlords\Applications;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LandlordOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_as_a_landlord_creates_a_pending_account_and_redirects_to_holding_page(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $component = Volt::test('pages.auth.register')
            ->set('name', 'New Landlord')
            ->set('email', 'newlandlord@example.test')
            ->set('phone', '0712345678')
            ->set('national_id', '12345678')
            ->set('property_location', 'Nyali, Mombasa')
            ->set('intended_unit_count', '5')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        $component->call('register')->assertRedirect(route('pending-approval', absolute: false));

        $user = User::where('email', 'newlandlord@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('landlord'));
        $this->assertEquals('inactive', $user->status);

        // A pending landlord is redirected away from their dashboard until approved.
        $this->actingAs($user)->get('/landlord/dashboard')->assertRedirect(route('pending-approval', absolute: false));
    }

    public function test_admin_can_approve_a_pending_landlord_application(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $pending = User::create([
            'name' => 'Pending Landlord',
            'email' => 'pending@example.test',
            'password' => bcrypt('password'),
            'status' => 'inactive',
        ]);
        $pending->assignRole('landlord');

        Livewire::actingAs($admin)
            ->test(Applications::class)
            ->call('approve', $pending->id);

        $this->assertEquals('active', $pending->fresh()->status);

        $this->actingAs($pending->fresh())->get('/landlord/dashboard')->assertOk();
    }

    public function test_a_suspended_user_is_logged_out_on_next_request(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'suspended']);

        $this->actingAs($landlord)->get('/landlord/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
