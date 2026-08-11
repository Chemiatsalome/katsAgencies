<?php

namespace Tests\Feature;

use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPortalLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_tenant_portal_pages_render_without_the_full_html_document_leaking_into_the_component_root(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        foreach (['tenant.dashboard', 'tenant.rent', 'tenant.pay', 'tenant.payments', 'tenant.statements', 'tenant.tenancy', 'tenant.contract', 'tenant.maintenance.index', 'tenant.maintenance.create', 'tenant.notifications.index', 'tenant.announcements.index', 'tenant.profile'] as $routeName) {
            $response = $this->actingAs($user)->get(route($routeName));

            $response->assertOk();

            // The regression this guards against: a Livewire component whose own
            // view wraps <x-...-layout> (a full <html> document) as its root gets
            // wire:id attached to <html> itself, which breaks on every subsequent
            // Livewire update. Every tenant page must use #[Layout(...)] instead.
            $this->assertDoesNotMatchRegularExpression('/<html\b[^>]*wire:(id|snapshot)/', $response->getContent());
            $this->assertMatchesRegularExpression('/<html\b/', $response->getContent());
        }
    }

    public function test_tenant_notification_bell_only_shows_the_logged_in_tenants_own_notifications(): void
    {
        $this->seed();

        $userA = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $userA->tenant->id)->first();
        $this->assertNotNull($otherTenancy, 'Seed data should include a second tenant to test isolation.');

        $userB = User::factory()->create();
        $userB->syncRoles(['tenant']);
        $otherTenancy->tenant->update(['user_id' => $userB->id]);

        app(\App\Services\NotificationService::class)->notify($userB->tenant, 'test_event', 'For B only', 'This belongs to tenant B.', ['in_app']);

        $response = $this->actingAs($userA)->get(route('tenant.notifications.index'));
        $response->assertOk();
        $response->assertDontSee('For B only');
    }
}
