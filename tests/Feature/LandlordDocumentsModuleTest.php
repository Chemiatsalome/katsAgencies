<?php

namespace Tests\Feature;

use App\Livewire\Documents\Index as DocumentsIndex;
use App\Livewire\Documents\Manager;
use App\Models\Tenancy;
use App\Models\User;
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
        Storage::fake('public');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy, 'category' => 'lease_agreement'])
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => $tenancy->getMorphClass(),
            'documentable_id' => $tenancy->id,
            'category' => 'lease_agreement',
            'original_filename' => 'lease.pdf',
        ]);
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
        Storage::fake('public');
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(Manager::class, ['model' => $tenancy, 'category' => 'lease_agreement'])
            ->set('file', UploadedFile::fake()->create('lease.pdf', 500, 'application/pdf'))
            ->call('upload');

        Livewire::actingAs($landlord)
            ->test(DocumentsIndex::class)
            ->assertSee('lease.pdf')
            ->set('category', 'receipt')
            ->assertDontSee('lease.pdf');
    }
}
