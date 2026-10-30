<?php

namespace Tests\Feature;

use App\Livewire\Applications\Index as ApplicationsIndex;
use App\Livewire\Maintenance\Index as MaintenanceIndex;
use App\Livewire\Tenancies\Index as TenanciesIndex;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Models\Document;
use App\Models\Manager;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers two audit findings fixed together: (B1) the tenants/tenancies/
 * applications/maintenance "view" manager permissions were enforced only in
 * the sidebar, never server-side, so a denied manager could still reach each
 * page by direct URL; (B2) DocumentPolicy compared a resolved landlord_id
 * straight against $user->id, which is never true for a manager, so every
 * manager was silently denied document view/delete regardless of their real
 * permissions. Kept as a permanent regression test — a security/
 * access-control fix with no other coverage in the suite.
 */
class ManagerPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, array $permissions, bool $allProperties = true): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Test Manager {$n}",
            'phone' => '070000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "test-manager-{$n}@example.test",
            'all_properties' => $allProperties,
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
        $managerUser->syncPermissions($permissions);
        $manager->update(['user_id' => $managerUser->id]);

        return $managerUser->fresh();
    }

    public function test_manager_without_tenants_view_permission_is_blocked_from_the_tenants_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenancies.view']);

        Livewire::actingAs($managerUser)->test(TenantsIndex::class)->assertStatus(403);
    }

    public function test_manager_with_tenants_view_permission_can_view_the_tenants_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(TenantsIndex::class)->assertStatus(200);
    }

    public function test_manager_without_tenancies_view_permission_is_blocked_from_the_tenancies_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(TenanciesIndex::class)->assertStatus(403);
    }

    public function test_manager_without_applications_view_permission_is_blocked_from_the_applications_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(ApplicationsIndex::class)->assertStatus(403);
    }

    public function test_manager_without_maintenance_or_complaints_view_permission_is_blocked_from_the_maintenance_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, ['tenants.view']);

        Livewire::actingAs($managerUser)->test(MaintenanceIndex::class)->assertStatus(403);
    }

    public function test_manager_with_only_complaints_view_permission_can_still_reach_the_maintenance_index(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        // The page covers both tasks and complaints — either view permission
        // is enough to reach it, not just maintenance.view.
        $managerUser = $this->makeManager($landlord, ['complaints.view']);

        Livewire::actingAs($managerUser)->test(MaintenanceIndex::class)->assertStatus(200);
    }

    public function test_manager_assigned_to_the_documents_property_can_delete_a_document_there(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $managerUser = $this->makeManager($landlord, ['properties.edit'], allProperties: false);
        $managerUser->manager->properties()->attach($property->id);

        $document = Document::create([
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => 'other',
            'original_filename' => 'title-deed.pdf',
            'path' => 'documents/title-deed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'uploaded_by' => $landlord->id,
        ]);

        Livewire::actingAs($managerUser)
            ->test(\App\Livewire\Documents\Index::class)
            ->call('delete', $document->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_manager_not_assigned_to_the_documents_property_cannot_delete_a_document_there(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        // Scoped to no properties at all — access control must still deny
        // this manager, not just "every manager" as the old bug did.
        $managerUser = $this->makeManager($landlord, ['properties.edit'], allProperties: false);

        $document = Document::create([
            'documentable_type' => Property::class,
            'documentable_id' => $property->id,
            'category' => 'other',
            'original_filename' => 'title-deed.pdf',
            'path' => 'documents/title-deed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'uploaded_by' => $landlord->id,
        ]);

        Livewire::actingAs($managerUser)
            ->test(\App\Livewire\Documents\Index::class)
            ->call('delete', $document->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }
}
