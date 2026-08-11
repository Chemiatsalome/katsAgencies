<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
