<?php

namespace Tests\Feature;

use App\Livewire\Admin\Platform\Occupancy;
use App\Livewire\Admin\Platform\Overview;
use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function makeLandlordWithUnits(string $name, int $vacantUnits): User
    {
        $landlord = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@test.test', 'password' => Hash::make('password')]);
        $landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $landlord->id, 'name' => $name.' Property', 'status' => 'active']);
        $block = Block::create(['property_id' => $property->id, 'name' => 'Block A']);

        for ($i = 1; $i <= $vacantUnits; $i++) {
            House::create([
                'block_id' => $block->id,
                'house_number' => (string) $i,
                'type' => 'bedsitter',
                'monthly_rent' => 10000,
                'deposit_amount' => 10000,
                'status' => 'vacant',
            ]);
        }

        return $landlord;
    }

    public function test_occupancy_overview_scopes_correctly_per_landlord(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin2@test.test', 'password' => Hash::make('password')]);
        $admin->assignRole('admin');

        $landlordA = $this->makeLandlordWithUnits('Landlord A', 3);
        $landlordB = $this->makeLandlordWithUnits('Landlord B', 5);

        // All landlords: totals from both.
        $component = Livewire::actingAs($admin)->test(Occupancy::class);
        $component->assertViewHas('totalUnits', 8);

        // Scoped to a single landlord.
        $component->set('landlordId', $landlordA->id)
            ->assertViewHas('totalUnits', 3);

        $component->set('landlordId', $landlordB->id)
            ->assertViewHas('totalUnits', 5);
    }

    public function test_platform_overview_landlord_filter_scopes_expected_rent(): void
    {
        $this->seed();

        // The Overview looks at the current 6-month window; the seeded data is historical (2024),
        // so generate this month's charges to have real, current-window figures to assert on.
        \Illuminate\Support\Facades\Artisan::call('rent:generate-charges');

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::create(['name' => 'Other Landlord', 'email' => 'other@test.test', 'password' => Hash::make('password')]);
        $otherLandlord->assignRole('landlord');

        $component = Livewire::actingAs($admin)->test(Overview::class);
        $allLandlordsExpected = $component->viewData('totalExpected');
        $this->assertGreaterThan(0, $allLandlordsExpected);

        $component->set('landlordId', $otherLandlord->id);
        $this->assertEquals(0, $component->viewData('totalExpected'));

        $component->set('landlordId', $landlord->id);
        $this->assertEquals($allLandlordsExpected, $component->viewData('totalExpected'));
    }
}
