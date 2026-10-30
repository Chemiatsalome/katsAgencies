<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Manager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Account-wide Settings (payout destination, late fees, rent reminders,
 * contract letterhead, renewal cycle) had no access control at all — the
 * route sat in the shared landlord|manager group and the component's
 * mount() never checked a role or permission, so any manager/agent could
 * reach the landlord's own M-Pesa payout configuration by using the
 * sidebar link every manager saw. There's no delegable permission for
 * Settings at all (by design); it must be landlord-only, full stop.
 * Confirmed live by the user before this fix.
 */
class SettingsAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord): User
    {
        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Manager',
            'phone' => '0700000099',
            'email' => 'settings-manager@example.test',
            'all_properties' => true,
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

        return $managerUser->fresh();
    }

    public function test_a_manager_is_blocked_from_settings_regardless_of_permissions(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord);

        // Even a manager with every delegable permission granted must still
        // be blocked — Settings has no permission toggle of its own.
        $managerUser->syncPermissions([
            'tenants.view', 'tenants.create', 'tenants.edit',
            'tenancies.view', 'tenancies.create', 'tenancies.terminate',
            'maintenance.view', 'maintenance.create', 'maintenance.schedule', 'maintenance.assign', 'maintenance.resolve',
            'complaints.view', 'complaints.respond',
            'applications.view', 'applications.review', 'applications.approve',
            'properties.create', 'properties.edit',
            'finance.view_rent_status',
        ]);

        Livewire::actingAs($managerUser)->test(SettingsIndex::class)->assertStatus(403);

        $this->get(route('manage.settings.index'))->assertForbidden();
    }

    public function test_a_landlord_can_still_reach_settings(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)->test(SettingsIndex::class)->assertStatus(200);
    }
}
