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
            ->call('updateStatus', $tenant->id, 'suspended', 'Repeated late rent payments.');

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

    /**
     * Product rule: an admin cannot independently suspend or deactivate a
     * manager — only the responsible landlord can, via Managers\Index.
     * Confirmed defect at the start of this task: the generic Users &
     * Roles screen has no role scoping at all, so a manager's status
     * select was fully functional there before this fix.
     */
    public function test_an_admin_cannot_suspend_a_manager_through_the_generic_users_screen(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $manager = \App\Models\Manager::create([
            'landlord_id' => $landlord->id, 'name' => 'Admin Rule Test Manager',
            'phone' => '0712300001', 'email' => 'admin-rule-test-manager@example.test',
            'all_properties' => true, 'status' => 'active',
            'token' => \App\Models\Manager::generateToken(), 'expires_at' => now()->addDays(7),
            'invited_at' => now(), 'activated_at' => now(),
        ]);
        $managerUser = User::create([
            'name' => $manager->name, 'email' => $manager->email,
            'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('startStatusChange', $managerUser->id, 'suspended')
            ->assertSet('pendingStatusUserId', null);

        $this->assertEquals('active', $managerUser->fresh()->status);

        // Defense in depth: updateStatus() itself refuses too, not only the entry point.
        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $managerUser->id, 'suspended', 'Attempted direct admin suspension.');

        $this->assertEquals('active', $managerUser->fresh()->status);
        $this->assertEquals('active', $manager->fresh()->status);
    }

    // --- Phase 3: reason capture for admin-driven account suspension -------------------

    public function test_an_admin_cannot_suspend_an_account_without_a_reason(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'suspended', '')
            ->assertHasErrors('reason');

        $this->assertEquals('active', $tenant->fresh()->status);
    }

    public function test_a_valid_admin_suspension_records_the_reason_actor_and_target(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'suspended', 'Reported suspicious activity.')
            ->assertHasNoErrors('reason');

        $this->assertEquals('suspended', $tenant->fresh()->status);

        $activity = \Spatie\Activitylog\Models\Activity::where('subject_type', User::class)
            ->where('subject_id', $tenant->id)->where('event', 'updated')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertEquals('Reported suspicious activity.', $activity->properties['reason'] ?? null);
        $this->assertEquals($admin->id, $activity->causer_id);
        $this->assertEquals($admin->getMorphClass(), $activity->causer_type);
    }

    public function test_reactivating_an_account_still_needs_no_reason(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();
        $tenant->update(['status' => 'suspended']);

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'active')
            ->assertHasNoErrors();

        $this->assertEquals('active', $tenant->fresh()->status);
    }

    /**
     * Confirms a second suspension cycle's reason doesn't overwrite the
     * first one's activity_log row.
     */
    public function test_a_subsequent_suspension_reason_does_not_overwrite_the_previous_ones_log_entry(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $tenant = User::role('tenant')->firstOrFail();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'suspended', 'First suspension reason.');

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'active');

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('updateStatus', $tenant->id, 'suspended', 'Second suspension reason.');

        $reasons = \Spatie\Activitylog\Models\Activity::where('subject_type', User::class)
            ->where('subject_id', $tenant->id)
            ->whereNotNull('properties->reason')
            ->orderBy('id')
            ->pluck('properties')
            ->map(fn ($p) => $p['reason'] ?? null)
            ->values();

        $this->assertEquals(['First suspension reason.', 'Second suspension reason.'], $reasons->all());
    }
}
