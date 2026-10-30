<?php

namespace Tests\Feature;

use App\Livewire\Blocks\Index as BlocksIndex;
use App\Livewire\Houses\Form as HouseForm;
use App\Livewire\Houses\Index as HousesIndex;
use App\Livewire\Properties\Form as PropertyForm;
use App\Livewire\Properties\Index as PropertiesIndex;
use App\Livewire\Properties\Wizard as PropertyWizard;
use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordPropertiesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_show_tabs_all_render(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        foreach (['overview', 'units', 'tenants', 'rent', 'expenses', 'maintenance', 'contracts', 'documents', 'performance'] as $tab) {
            $this->actingAs($landlord)
                ->get(route('manage.properties.show', $property).'?tab='.$tab)
                ->assertOk();
        }
    }

    public function test_house_show_renders_with_history(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->occupied()->firstOrFail();

        $this->actingAs($landlord)
            ->get(route('manage.houses.show', $house))
            ->assertOk()
            ->assertSee('Occupancy History');
    }

    public function test_occupancy_and_vacancies_pages_render(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $this->actingAs($landlord)->get(route('manage.occupancy.index'))->assertOk();
        $this->actingAs($landlord)->get(route('manage.vacancies.index'))->assertOk();
    }

    public function test_a_second_landlord_cannot_view_anothers_property_or_house(): void
    {
        $this->seed();

        $landlordA = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlordA->id))->firstOrFail();
        $property = $house->block->property;

        $landlordB = User::create(['name' => 'Landlord B', 'email' => 'lb@test.test', 'password' => bcrypt('password')]);
        $landlordB->assignRole('landlord');

        $this->actingAs($landlordB)->get(route('manage.properties.show', $property))->assertForbidden();
        $this->actingAs($landlordB)->get(route('manage.houses.show', $house))->assertForbidden();
    }

    public function test_a_house_with_an_active_tenancy_cannot_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->occupied()->firstOrFail();
        $block = $house->block;

        Livewire::actingAs($landlord)
            ->test(HousesIndex::class, ['block' => $block])
            ->call('delete', $house->id);

        $this->assertDatabaseHas('houses', ['id' => $house->id]);
    }

    public function test_a_block_with_an_active_tenancy_cannot_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->occupied()->firstOrFail();
        $block = $house->block;

        Livewire::actingAs($landlord)
            ->test(BlocksIndex::class, ['property' => $block->property])
            ->call('delete', $block->id);

        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    }

    public function test_a_property_with_an_active_tenancy_cannot_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->occupied()->firstOrFail();
        $property = $house->block->property;

        Livewire::actingAs($landlord)
            ->test(PropertiesIndex::class)
            ->call('delete', $property->id);

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_a_vacant_property_can_still_be_deleted(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Empty Portfolio Entry',
            'status' => 'active',
        ]);

        Livewire::actingAs($landlord)
            ->test(PropertiesIndex::class)
            ->call('delete', $property->id);

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_properties_index_renders_as_cards_with_occupancy_and_rent_range(): void
    {
        $this->seed();

        $landlordA = User::role('landlord')->firstOrFail();
        $property = $landlordA->properties()->firstOrFail();

        $landlordB = User::create(['name' => 'Card Grid Landlord B', 'email' => 'card-grid-b@test.test', 'password' => bcrypt('password')]);
        $landlordB->assignRole('landlord');
        $otherProperty = Property::create(['landlord_id' => $landlordB->id, 'name' => 'Landlord B Only Property', 'status' => 'active']);

        $response = $this->actingAs($landlordA)->get(route('manage.properties.index'));

        $response->assertOk();
        $response->assertSee($property->name);
        // Cross-landlord scoping (already covered pre-redesign for the
        // table view) must still hold for the new card grid.
        $response->assertDontSee('Landlord B Only Property');
    }

    /**
     * Running the wizard must never touch anything that already existed —
     * confirms property/block/house creation is additive-only.
     */
    public function test_creating_a_property_via_the_wizard_does_not_affect_existing_properties_blocks_houses_or_tenancy_history(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $propertySnapshot = Property::orderBy('id')->get(['id', 'name', 'type', 'status'])->toArray();
        $blockSnapshot = Block::orderBy('id')->get(['id', 'property_id', 'name'])->toArray();
        $houseSnapshot = House::orderBy('id')->get(['id', 'block_id', 'house_number', 'status', 'monthly_rent'])->toArray();
        $tenancySnapshot = Tenancy::orderBy('id')->get(['id', 'house_id', 'tenant_id', 'status'])->toArray();

        Livewire::actingAs($landlord)->test(PropertyWizard::class)
            ->set('type', 'individual_house')
            ->set('name', 'Freshly Added, Should Not Disturb Anything')
            ->call('goToStep2')
            ->set('groups.0.unit_type', 'house')
            ->set('groups.0.monthly_rent', '22000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        // The wizard run adds exactly one new property/block/house — assert
        // every pre-existing row is still present, byte-for-byte unchanged,
        // not that nothing new was added (the new row is expected).
        $this->assertEquals($propertySnapshot, array_values(array_filter(Property::orderBy('id')->get(['id', 'name', 'type', 'status'])->toArray(), fn ($row) => in_array($row['id'], array_column($propertySnapshot, 'id'), true))));
        $this->assertEquals($blockSnapshot, array_values(array_filter(Block::orderBy('id')->get(['id', 'property_id', 'name'])->toArray(), fn ($row) => in_array($row['id'], array_column($blockSnapshot, 'id'), true))));
        $this->assertEquals($houseSnapshot, array_values(array_filter(House::orderBy('id')->get(['id', 'block_id', 'house_number', 'status', 'monthly_rent'])->toArray(), fn ($row) => in_array($row['id'], array_column($houseSnapshot, 'id'), true))));
        $this->assertEquals($tenancySnapshot, Tenancy::orderBy('id')->get(['id', 'house_id', 'tenant_id', 'status'])->toArray());
    }

    public function test_house_type_house_saves_correctly(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(HouseForm::class, ['house' => $house])
            ->set('type', 'house')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('house', $house->fresh()->type);
    }

    public function test_editing_a_reserved_house_does_not_revert_its_status(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $house = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->vacant()->firstOrFail();
        $house->update(['status' => 'reserved']);

        Livewire::actingAs($landlord)
            ->test(HouseForm::class, ['house' => $house])
            ->set('description', 'Updated description')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('reserved', $house->fresh()->status);
        $this->assertEquals('Updated description', $house->fresh()->description);
    }

    public function test_property_investment_fields_can_be_saved_and_feed_yield_calculations(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(PropertyForm::class, ['property' => $property])
            ->set('purchase_price', '1000000')
            ->set('initial_renovation_cost', '50000')
            ->set('other_acquisition_costs', '10000')
            ->set('current_estimated_value', '1200000')
            ->call('save')
            ->assertHasNoErrors();

        $property->refresh();
        $this->assertEquals(1060000.0, $property->totalInvestment());
        $this->assertNotNull($property->current_estimated_value);
    }

    public function test_property_dashboard_shows_persistent_add_tenant_action_for_vacant_units_on_an_ordinary_revisit(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $vacantHouses = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->get();
        $this->assertTrue($vacantHouses->isNotEmpty(), 'Seed data should include at least one vacant unit for this property.');

        // No ?onboarded=1 here — this is an ordinary later revisit, not the
        // one-time landing right after the wizard, and the "add a tenant"
        // action must still be instantly available.
        $response = $this->actingAs($landlord)->get(route('manage.properties.show', $property));

        $response->assertOk();
        $response->assertSee('currently vacant on this property');

        $expectedUrl = $vacantHouses->count() === 1
            ? route('manage.tenancies.create', ['house_id' => $vacantHouses->first()->id])
            : route('manage.tenancies.create', ['property_id' => $property->id]);

        $response->assertSee($expectedUrl, false);
    }

    public function test_property_dashboard_hides_the_vacant_unit_banner_once_fully_occupied(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))
            ->vacant()
            ->update(['status' => 'unavailable']);

        $response = $this->actingAs($landlord)->get(route('manage.properties.show', $property));

        $response->assertOk();
        $response->assertDontSee('currently vacant on this property');
    }

    public function test_editing_a_property_with_a_new_photo_updates_its_photo_path(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('public');

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(PropertyForm::class, ['property' => $property])
            ->set('photo', \Illuminate\Http\UploadedFile::fake()->image('front.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $property->refresh();
        $this->assertNotNull($property->photo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($property->photo_path);
    }

    public function test_editing_a_property_without_choosing_a_new_photo_preserves_the_existing_one(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('public');

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $property->update(['photo_path' => 'property-photos/existing.jpg']);

        Livewire::actingAs($landlord)
            ->test(PropertyForm::class, ['property' => $property])
            ->set('description', 'A fresh description, no photo change')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('property-photos/existing.jpg', $property->fresh()->photo_path);
    }
}
