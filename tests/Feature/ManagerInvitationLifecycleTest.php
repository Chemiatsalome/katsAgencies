<?php

namespace Tests\Feature;

use App\Livewire\ManagerInvitations\Accept;
use App\Livewire\Managers\Form;
use App\Models\Manager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagerInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_manager_invitation_lifecycle(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Form::class)
            ->set('name', 'Jane Manager')
            ->set('phone', '0700000001')
            ->set('email', 'jane-manager@test.test')
            ->call('goToStep2')
            ->assertSet('step', 2)
            ->set('all_properties', true)
            ->set('permissions', ['tenants.view', 'maintenance.view', 'maintenance.resolve'])
            ->call('save')
            ->assertSet('step', 3);

        $manager = Manager::where('email', 'jane-manager@test.test')->firstOrFail();
        $this->assertSame('invited', $manager->status);
        $this->assertTrue($manager->all_properties);
        $this->assertEqualsCanonicalizing(['tenants.view', 'maintenance.view', 'maintenance.resolve'], $manager->permissions);

        Livewire::test(Accept::class, ['token' => $manager->token])
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertRedirect(route('manager.dashboard'));

        $manager->refresh();
        $this->assertSame('active', $manager->status);
        $this->assertNotNull($manager->user_id);

        $managerUser = User::findOrFail($manager->user_id);
        $this->assertTrue($managerUser->hasRole('manager'));
        $this->assertTrue($managerUser->can('tenants.view'));
        $this->assertTrue($managerUser->can('maintenance.resolve'));
        $this->assertFalse($managerUser->can('finance.overview'));

        $this->actingAs($managerUser)
            ->get(route('manager.dashboard'))
            ->assertOk();
    }

    public function test_editing_an_active_managers_permissions_takes_effect_immediately(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Active Manager',
            'phone' => '0700000002',
            'email' => 'active-manager@test.test',
            'all_properties' => false,
            'permissions' => ['tenants.view'],
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);
        $manager->properties()->attach($property->id);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions($manager->permissions);
        $manager->update(['user_id' => $managerUser->id]);

        $this->assertTrue($managerUser->fresh()->can('tenants.view'));
        $this->assertFalse($managerUser->fresh()->can('tenants.create'));

        Livewire::actingAs($landlord)
            ->test(Form::class, ['manager' => $manager])
            ->call('goToStep2')
            ->set('permissions', ['tenants.view', 'tenants.create'])
            ->call('save');

        $this->assertTrue($managerUser->fresh()->can('tenants.create'));
    }
}
