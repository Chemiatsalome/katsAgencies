<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertDontSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    // --- F1: generic Breeze account self-deletion, closed ------------------------------
    //
    // The destructive path used to be reachable by every role through the
    // shared /profile page (App\Livewire\Profile\Index) with no business-
    // relationship checks at all — a landlord with properties, or any other
    // role, could permanently delete their own User with nothing but their
    // own password, cascading through everything FK-chained beneath it. The
    // Volt component that held both the UI and the deleteUser() method
    // (resources/views/livewire/profile/delete-user-form.blade.php) has been
    // removed entirely, not just unlinked from the page.

    public function test_the_generic_account_deletion_form_is_not_shown_to_any_role_on_the_shared_profile_page(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        // A bare role-assigned factory user stands in fine for tenant/admin,
        // but the sidebar this page shares with every other authenticated
        // view resolves a manager's landlordId() via their real Manager
        // relationship (see sidebar.blade.php) — a manager with no such row
        // is not a state the app ever actually puts someone in, so this
        // builds a real one rather than tripping an unrelated null-safety
        // gap that has nothing to do with F1.
        $manager = \App\Models\Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Profile Test Manager',
            'phone' => '0712000111',
            'email' => 'profile-test-manager@example.test',
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

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach ([$landlord, $managerUser->fresh(), $tenant, $admin] as $user) {
            $this->actingAs($user)->get('/profile')
                ->assertOk()
                ->assertDontSeeVolt('profile.delete-user-form')
                ->assertDontSee('Delete Account');

            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
    }

    public function test_the_generic_account_deletion_backend_action_no_longer_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        // Not merely hidden from the page — the component (and the
        // deleteUser() method that lived inside it) is gone, so there is
        // nothing left to mount or call it on.
        $this->expectException(\Throwable::class);

        Volt::test('profile.delete-user-form');
    }
}
