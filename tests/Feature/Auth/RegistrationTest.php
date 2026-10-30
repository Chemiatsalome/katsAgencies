<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('phone', '0712345678')
            ->set('national_id', '12345678')
            ->set('property_location', 'Nyali, Mombasa')
            ->set('intended_unit_count', '5')
            ->set('password', 'password1234')
            ->set('password_confirmation', 'password1234');

        $component->call('register');

        // Self-registration always creates a landlord account pending admin
        // approval — tenants and managers only arrive via invitation.
        $component->assertRedirect(route('pending-approval', absolute: false));

        $this->assertAuthenticated();
    }
}
