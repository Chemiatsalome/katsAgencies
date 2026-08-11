<?php

namespace Tests\Feature;

use App\Livewire\Tenants\Import;
use App\Models\House;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class TenantImportModuleTest extends TestCase
{
    use RefreshDatabase;

    private function vacantHousesFor(User $landlord, int $count = 2)
    {
        $houses = House::vacant()
            ->with('block.property')
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))
            ->take($count)
            ->get();

        $this->assertGreaterThanOrEqual($count, $houses->count(), 'Seed data should include enough vacant units for this landlord.');

        return $houses;
    }

    public function test_csv_import_buckets_rows_correctly_and_commits_only_valid_ones(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        [$houseA, $houseB] = $this->vacantHousesFor($landlord, 2);

        $csv = "Property,Block,Unit,Name,Phone,Rent,Due Date\n";
        $csv .= "{$houseA->block->property->name},{$houseA->block->name},{$houseA->house_number},Valid Tenant One,0700111222,{$houseA->monthly_rent},5\n";
        $csv .= "{$houseB->block->property->name},{$houseB->block->name},{$houseB->house_number},Valid Tenant Two,0700111333,{$houseB->monthly_rent},1\n";
        $csv .= "Not A Real Property,Block Z,99,Ghost Tenant,0700111444,10000,1\n";
        $csv .= "{$houseA->block->property->name},NoSuchBlock,999,Missing Unit Tenant,0700111555,10000,1\n";
        $csv .= ",,,,,,\n"; // fully blank line, should be skipped
        $csv .= "{$houseA->block->property->name},{$houseA->block->name},{$houseA->house_number},No Rent Tenant,0700111666,,1\n"; // invalid: no rent

        $file = UploadedFile::fake()->createWithContent('tenants.csv', $csv);

        $component = Livewire::actingAs($landlord)
            ->test(Import::class)
            ->set('file', $file)
            ->call('preview')
            ->assertHasNoErrors();

        $rows = $component->get('rows');
        $this->assertCount(5, $rows); // blank line skipped

        $buckets = collect($rows)->pluck('bucket')->all();
        $this->assertEqualsCanonicalizing(
            ['valid', 'valid', 'unknown_property', 'missing_unit', 'invalid'],
            $buckets
        );

        $component->call('commit');

        $this->assertDatabaseHas('tenants', ['name' => 'Valid Tenant One', 'phone' => '0700111222']);
        $this->assertDatabaseHas('tenants', ['name' => 'Valid Tenant Two', 'phone' => '0700111333']);
        $this->assertDatabaseMissing('tenants', ['name' => 'Ghost Tenant']);
        $this->assertDatabaseMissing('tenants', ['name' => 'Missing Unit Tenant']);
        $this->assertDatabaseMissing('tenants', ['name' => 'No Rent Tenant']);

        $this->assertEquals('occupied', $houseA->fresh()->status);
        $this->assertEquals('occupied', $houseB->fresh()->status);
    }

    public function test_importing_the_same_row_twice_marks_the_second_as_duplicate(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        [$house] = $this->vacantHousesFor($landlord, 1);

        $csv = "Property,Block,Unit,Name,Phone,Rent,Due Date\n";
        $csv .= "{$house->block->property->name},{$house->block->name},{$house->house_number},Dup Tenant,0700999888,{$house->monthly_rent},1\n";
        $csv .= "{$house->block->property->name},{$house->block->name},{$house->house_number},Dup Tenant,0700999888,{$house->monthly_rent},1\n";

        $file = UploadedFile::fake()->createWithContent('tenants.csv', $csv);

        $component = Livewire::actingAs($landlord)
            ->test(Import::class)
            ->set('file', $file)
            ->call('preview');

        $rows = $component->get('rows');
        $this->assertEquals('valid', $rows[0]['bucket']);
        $this->assertEquals('duplicate', $rows[1]['bucket']);
    }

    public function test_landlord_can_send_a_claim_invitation_to_an_imported_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        [$house] = $this->vacantHousesFor($landlord, 1);

        $csv = "Property,Block,Unit,Name,Phone,Rent,Due Date\n";
        $csv .= "{$house->block->property->name},{$house->block->name},{$house->house_number},Import Claim Tenant,0700555444,{$house->monthly_rent},1\n";

        $file = UploadedFile::fake()->createWithContent('tenants.csv', $csv);

        Livewire::actingAs($landlord)
            ->test(Import::class)
            ->set('file', $file)
            ->call('preview')
            ->call('commit');

        $tenant = Tenant::where('phone', '0700555444')->firstOrFail();
        $this->assertNull($tenant->user_id);

        Livewire::actingAs($landlord)
            ->test(Import::class)
            ->call('sendInvitation', $tenant->id);

        $this->assertDatabaseHas('tenant_invitations', [
            'existing_tenant_id' => $tenant->id,
            'status' => 'pending',
        ]);
    }
}
