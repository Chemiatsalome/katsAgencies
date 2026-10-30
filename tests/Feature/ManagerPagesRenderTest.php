<?php

namespace Tests\Feature;

use App\Models\Manager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_management_pages_render_for_landlord(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Render Manager',
            'phone' => '0700000009',
            'email' => 'render-manager@test.test',
            'all_properties' => true,
            'permissions' => ['tenants.view'],
            'status' => 'active',
            'token' => Manager::generateToken(),
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

        $this->actingAs($landlord)->get(route('manage.managers.index'))->assertOk()->assertDontSee('<html wire:id', false);
        $this->actingAs($landlord)->get(route('manage.managers.create'))->assertOk();
        $this->actingAs($landlord)->get(route('manage.managers.edit', $manager))->assertOk();
        $this->actingAs($landlord)->get(route('manage.managers.activity', $manager))->assertOk();

        // A manager must never reach the manager-management pages themselves.
        $this->actingAs($managerUser)->get(route('manage.managers.index'))->assertForbidden();
    }

    public function test_manager_invitation_accept_page_renders(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Pending Manager',
            'phone' => '0700000010',
            'email' => 'pending-manager@test.test',
            'all_properties' => true,
            'permissions' => [],
            'status' => 'invited',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
        ]);

        $this->get(route('manager-invitations.accept', $manager->token))->assertOk();
    }
}
