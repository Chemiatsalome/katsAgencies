<?php

namespace Tests\Feature;

use App\Livewire\Analytics\Performance;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordAnalyticsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_page_computes_roi_from_seeded_property_figures(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $property->update([
            'purchase_price' => 2000000,
            'initial_renovation_cost' => 200000,
            'other_acquisition_costs' => 50000,
            'current_estimated_value' => 2500000,
        ]);

        Livewire::actingAs($landlord)
            ->test(Performance::class)
            ->assertOk()
            ->assertViewHas('rows', function ($rows) use ($property) {
                $row = $rows->firstWhere('property.id', $property->id);

                return $row !== null
                    && $row->investment === 2250000.0
                    && $row->grossYield === $property->grossYield();
            });
    }

    public function test_performance_page_only_includes_the_current_landlords_properties(): void
    {
        $this->seed();

        $actingLandlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create();
        $otherLandlord->syncRoles(['landlord']);
        Property::create([
            'landlord_id' => $otherLandlord->id, 'name' => 'Not Mine Apartments', 'type' => 'apartment',
            'address' => '1 Elsewhere Rd', 'status' => 'active',
        ]);

        Livewire::actingAs($actingLandlord)
            ->test(Performance::class)
            ->assertDontSee('Not Mine Apartments');
    }
}
