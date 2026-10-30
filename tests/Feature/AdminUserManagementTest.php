<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_another_users_role_and_status(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateRole', $tenant->id, 'landlord')
            ->call('updateStatus', $tenant->id, 'suspended');

        $tenant->refresh();
        $this->assertTrue($tenant->hasRole('landlord'));
        $this->assertEquals('suspended', $tenant->status);
    }

    public function test_an_unknown_role_or_status_value_is_rejected(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateRole', $tenant->id, 'super-admin')
            ->assertStatus(422);
    }

    public function test_an_admin_cannot_remove_their_own_admin_role(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateRole', $admin->id, 'landlord');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_an_admin_cannot_change_their_own_status(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $admin->id, 'suspended');

        $this->assertEquals('active', $admin->fresh()->status);
    }

    public function test_a_deactivated_tenant_is_locked_out_on_their_next_request(): void
    {
        $this->seed();

        $tenant = User::role('tenant')->firstOrFail();
        $tenant->update(['status' => 'inactive']);

        $this->actingAs($tenant)->get(route('tenant.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
