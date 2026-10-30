<?php

namespace Tests\Feature;

use App\Livewire\Documents\Index as DocumentsIndex;
use App\Livewire\Documents\Manager;
use App\Models\Complaint;
use App\Models\Document;
use App\Models\House;
use App\Models\Manager as ManagerModel;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordDocumentsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_upload_a_document_to_a_tenancy(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy, 'category' => 'property_document'])
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => $tenancy->getMorphClass(),
            'documentable_id' => $tenancy->id,
            'category' => 'property_document',
            'original_filename' => 'lease.pdf',
        ]);
    }

    /** Lease agreements are deliberately excluded from this generic uploader — they only ever come from the Contract section, which keeps a real Contract row (version, status, signing history) behind the file. */
    /**
     * DocumentPolicy's Tenancy branch only ever checked
     * Tenancy::accessibleBy() — which chains through House/Block/Property
     * and only ever matches the landlord/manager side. A tenant downloading
     * their own signed lease (or their own ID document, also stored against
     * the Tenancy) hit a 403, since nothing in that branch ever checked
     * whether the viewer *is* the tenant this tenancy belongs to.
     */
    public function test_a_tenant_can_download_their_own_lease_agreement_document_but_not_someone_elses(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'contract-download-tenant@example.test', 'password'
        );

        $contract = app(ContractService::class)->generateForTenancy($tenancy, \App\Models\ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Download Test Template',
            'body' => 'Tenant: {{tenant_name}}',
            'is_default' => true,
            'is_active' => true,
        ]));
        $document = $contract->document;

        // The landlord who owns this tenancy — already worked, must keep working.
        $this->actingAs($landlord)->get(route('documents.show', $document))->assertOk();

        // The tenant this lease actually belongs to — this is the fix.
        $this->actingAs($tenantUser)->get(route('documents.show', $document))->assertOk();

        // A different tenant entirely must still be refused.
        $otherTenant = Tenant::create(['name' => 'Someone Else', 'phone' => '0700000999', 'payment_due_day' => 1]);
        $otherTenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $otherTenant, $otherTenant->name, 'other-tenant@example.test', 'password'
        );
        $this->actingAs($otherTenantUser)->get(route('documents.show', $document))->assertForbidden();
    }

    public function test_lease_agreement_category_is_rejected_by_the_generic_document_uploader(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy])
            ->set('category', 'lease_agreement')
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('category');

        $this->assertDatabaseMissing('documents', ['category' => 'lease_agreement', 'original_filename' => 'lease.pdf']);
    }

    public function test_landlord_cannot_open_the_document_manager_for_another_landlords_tenancy(): void
    {
        $this->seed();

        $actingLandlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $otherLandlord->id,
            'name' => 'Other Landlord Property',
            'type' => 'apartment',
            'address' => '123 Elsewhere Rd',
            'status' => 'active',
        ]);
        $otherBlock = \App\Models\Block::create(['property_id' => $otherProperty->id, 'name' => 'Main']);
        $otherHouse = \App\Models\House::create([
            'block_id' => $otherBlock->id, 'house_number' => 'A1', 'monthly_rent' => 5000,
            'deposit_amount' => 5000, 'status' => 'occupied',
        ]);
        $otherTenant = \App\Models\Tenant::create(['name' => 'Other Tenant', 'phone' => '0711111111']);
        $otherTenancy = Tenancy::create([
            'tenant_id' => $otherTenant->id, 'house_id' => $otherHouse->id, 'move_in_date' => now()->format('Y-m-d'),
            'monthly_rent' => 5000, 'deposit_required' => 5000, 'status' => 'active',
        ]);

        Livewire::actingAs($actingLandlord)
            ->test(Manager::class, ['model' => $otherTenancy])
            ->assertForbidden();
    }

    public function test_documents_index_lists_uploaded_documents_filtered_by_category(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy, 'category' => 'property_document'])
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('save');

        Livewire::actingAs($landlord)
            ->test(DocumentsIndex::class)
            ->assertSee('lease.pdf')
            ->set('category', 'receipt')
            ->assertDontSee('lease.pdf');
    }

    public function test_landlord_can_view_a_document_a_tenant_attached_to_their_own_complaint(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $complaint = Complaint::create([
            'property_id' => $property->id, 'category' => 'plumbing', 'subject' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.', 'priority' => 'medium', 'status' => 'submitted',
        ]);
        $document = Document::create([
            'documentable_type' => $complaint->getMorphClass(), 'documentable_id' => $complaint->id,
            'category' => 'other', 'original_filename' => 'leak.jpg', 'path' => 'documents/leak.jpg',
            'mime_type' => 'image/jpeg', 'size' => 1000,
        ]);

        // Before the DocumentPolicy fix, Complaint had no case in
        // belongsToLandlord() — this always evaluated to false, so not even
        // the owning landlord could view/delete a complaint-attached document.
        $this->assertTrue($landlord->can('view', $document));
        $this->assertTrue($landlord->can('delete', $document));

        Livewire::actingAs($landlord)
            ->test(DocumentsIndex::class)
            ->assertSee('leak.jpg');
    }

    public function test_a_manager_does_not_see_upload_or_delete_controls_they_cannot_use(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy, 'category' => 'lease_agreement'])
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('save');

        $managerUser = User::factory()->create(['status' => 'active']);
        $managerUser->assignRole('manager');
        ManagerModel::create([
            'landlord_id' => $landlord->id, 'user_id' => $managerUser->id, 'name' => $managerUser->name,
            'phone' => '0700000222', 'email' => $managerUser->email, 'all_properties' => true, 'status' => 'active',
            'token' => ManagerModel::generateToken(), 'expires_at' => now()->addDays(7), 'activated_at' => now(),
        ]);

        $html = Livewire::actingAs($managerUser)
            ->test(Manager::class, ['model' => $tenancy])
            ->html();

        $this->assertStringNotContainsString('Upload Document', $html);
        $this->assertStringNotContainsString('wire:click="delete', $html);
    }

    public function test_deleting_a_property_removes_its_own_and_its_houses_documents(): void
    {
        Storage::fake('local');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = Property::create([
            'landlord_id' => $landlord->id, 'name' => 'Deletable Property', 'type' => 'apartment', 'status' => 'active',
        ]);
        $block = \App\Models\Block::create(['property_id' => $property->id, 'name' => 'Main']);
        $house = House::create([
            'block_id' => $block->id, 'house_number' => 'D1', 'monthly_rent' => 5000,
            'deposit_amount' => 5000, 'status' => 'vacant',
        ]);

        $propertyDoc = Document::create([
            'documentable_type' => $property->getMorphClass(), 'documentable_id' => $property->id,
            'category' => 'property_document', 'original_filename' => 'title-deed.pdf',
            'path' => UploadedFile::fake()->create('title-deed.pdf', 100)->store('documents', 'local'),
            'mime_type' => 'application/pdf', 'size' => 100,
        ]);
        $houseDoc = Document::create([
            'documentable_type' => $house->getMorphClass(), 'documentable_id' => $house->id,
            'category' => 'inspection_report', 'original_filename' => 'inspection.pdf',
            'path' => UploadedFile::fake()->create('inspection.pdf', 100)->store('documents', 'local'),
            'mime_type' => 'application/pdf', 'size' => 100,
        ]);

        Storage::disk('local')->assertExists($propertyDoc->path);
        Storage::disk('local')->assertExists($houseDoc->path);

        $property->delete();

        $this->assertDatabaseMissing('documents', ['id' => $propertyDoc->id]);
        $this->assertDatabaseMissing('documents', ['id' => $houseDoc->id]);
        Storage::disk('local')->assertMissing($propertyDoc->path);
        Storage::disk('local')->assertMissing($houseDoc->path);
    }
}
