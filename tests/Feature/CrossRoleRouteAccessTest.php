<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /tenant/* routes are a tenant's own self-service portal — scoped to
 * role:tenant regardless of who owns the underlying tenancy, so a landlord
 * (even the one who owns that tenant) is correctly blocked from them. The
 * landlord's equivalent view is Manage > Tenancies > {tenancy}, not the
 * tenant portal. This was working as intended, but the 403 it produced was
 * Spatie's raw "User does not have the right roles." with no app styling —
 * resources/views/errors/403.blade.php now gives it a real page.
 */
class CrossRoleRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_landlord_is_blocked_from_the_tenant_self_service_portal_with_a_friendly_page(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $response = $this->actingAs($landlord)->get('/tenant/payments');

        $response->assertForbidden();
        $response->assertSee("This page isn't available for your account", false);
        $response->assertDontSee('User does not have the right roles');
    }

    public function test_a_tenant_is_blocked_from_the_landlord_management_area(): void
    {
        $this->seed();

        $tenant = User::role('tenant')->first() ?? tap(User::factory()->create(), function (User $user) {
            $user->syncRoles(['tenant']);
        });

        $response = $this->actingAs($tenant)->get('/manage/tenants');

        $response->assertForbidden();
        $response->assertSee("This page isn't available for your account", false);
    }
}
