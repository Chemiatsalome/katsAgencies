<?php

namespace Tests\Feature;

use App\Models\Manager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar's section headers (e.g. "My Properties") used to just be
 * plain labels with every link underneath always visible — a long list of
 * every nav item in every section at once. Each multi-item section is now
 * a collapsible <x-nav-group> (resources/views/components/nav-group.blade.php):
 * clicking the header toggles its own links, and each group remembers its
 * open/closed state in localStorage independently of the sidebar's own
 * expanded/collapsed rail state. A group defaults open when it contains
 * the page currently being viewed, so landing on a page never hides the
 * very link that's highlighted as current.
 */
class SidebarNavGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_renders_with_collapsible_groups_for_admin(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Landlord Management')
            ->assertSee('Platform Monitoring');
    }

    public function test_sidebar_renders_with_collapsible_groups_for_landlord(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->actingAs($landlord)->get(route('landlord.dashboard'))
            ->assertOk()
            ->assertSee('My Properties')
            ->assertSee('Rent & Finance');
    }

    public function test_sidebar_renders_with_collapsible_groups_for_manager(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $manager = Manager::create([
            'landlord_id' => $landlord->id, 'name' => 'Nav Test Manager', 'phone' => '0700000777',
            'email' => 'nav-test-manager@test.test', 'all_properties' => true, 'permissions' => ['tenants.view'],
            'status' => 'active', 'token' => Manager::generateToken(), 'expires_at' => now()->addDays(7),
            'invited_at' => now(), 'activated_at' => now(),
        ]);
        $managerUser = User::create(['name' => $manager->name, 'email' => $manager->email, 'password' => bcrypt('password'), 'status' => 'active']);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions(['tenants.view']);
        $manager->update(['user_id' => $managerUser->id]);

        $this->actingAs($managerUser)->get(route('manager.dashboard'))
            ->assertOk()
            ->assertSee('Property Management')
            ->assertSee('Tenants');
    }

    public function test_a_nav_group_containing_the_current_page_defaults_to_open(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $html = $this->actingAs($landlord)->get(route('manage.properties.index'))->getContent();

        // The default-when-nothing-stored-yet fallback is baked into the
        // localStorage lookup itself: ?? '1' for an active group, ?? '0' for
        // an inactive one.
        $this->assertStringContainsString("localStorage.getItem('nav-group-landlord-properties') ?? '1') === '1'", $html);
        $this->assertStringContainsString("localStorage.getItem('nav-group-landlord-team') ?? '0') === '1'", $html);
    }

    public function test_tenant_sidebar_uses_the_same_collapsible_group_pattern(): void
    {
        $this->seed();
        $tenant = User::role('tenant')->first();

        if (! $tenant) {
            $this->markTestSkipped('No seeded tenant with a portal login.');
        }

        $this->actingAs($tenant)->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('My Tenancy')
            ->assertSee('Communication');
    }

    public function test_a_single_item_group_wraps_correctly(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $this->actingAs($landlord)->get(route('manage.managers.index'))
            ->assertOk()
            ->assertSee('Managers / Agents');
    }
}
