<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Complaint;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\Manager;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ManagerModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagerUser(int $landlordId, string $email, bool $allProperties, ?Property $onlyProperty = null): User
    {
        $manager = Manager::create([
            'landlord_id' => $landlordId,
            'name' => 'Test Manager',
            'phone' => '0700000000',
            'email' => $email,
            'all_properties' => $allProperties,
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        if (! $allProperties && $onlyProperty) {
            $manager->properties()->attach($onlyProperty->id);
        }

        $user = User::create([
            'name' => $manager->name,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $user->assignRole('manager');
        $manager->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    public function test_manager_scoped_to_one_property_cannot_access_another_propertys_data(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $properties = $landlord->properties()->get();
        $this->assertGreaterThanOrEqual(2, $properties->count(), 'Seed data must contain at least two properties under the landlord');

        [$propertyA, $propertyB] = [$properties[0], $properties[1]];
        $manager = $this->makeManagerUser($landlord->id, 'scoped-manager@test.test', false, $propertyA);

        $this->actingAs($manager)->get(route('manage.properties.show', $propertyA))->assertOk();
        $this->actingAs($manager)->get(route('manage.properties.show', $propertyB))->assertForbidden();

        $houseA = $propertyA->houses()->first();
        $houseB = $propertyB->houses()->first();

        if ($houseA) {
            $this->actingAs($manager)->get(route('manage.houses.show', $houseA))->assertOk();
        }
        if ($houseB) {
            $this->actingAs($manager)->get(route('manage.houses.show', $houseB))->assertForbidden();
        }

        $tenancyA = Tenancy::whereHas('house.block.property', fn ($q) => $q->whereKey($propertyA->id))->first();
        $tenancyB = Tenancy::whereHas('house.block.property', fn ($q) => $q->whereKey($propertyB->id))->first();

        if ($tenancyA) {
            $this->actingAs($manager)->get(route('manage.tenancies.show', $tenancyA))->assertOk();
            $this->actingAs($manager)->get(route('manage.tenants.show', $tenancyA->tenant))->assertOk();
        }
        if ($tenancyB) {
            $this->actingAs($manager)->get(route('manage.tenancies.show', $tenancyB))->assertForbidden();
            $this->actingAs($manager)->get(route('manage.tenants.show', $tenancyB->tenant))->assertForbidden();
        }

        // Complaint/MaintenanceTask/MaintenanceSchedule have no dedicated "show"
        // route yet, so their IDOR protection is checked at the Policy/Gate
        // level directly — the same scopeAccessibleBy() a future route would use.
        $complaintA = Complaint::create(['property_id' => $propertyA->id, 'subject' => 'Leak', 'description' => 'Leak', 'category' => 'plumbing']);
        $complaintB = Complaint::create(['property_id' => $propertyB->id, 'subject' => 'Leak', 'description' => 'Leak', 'category' => 'plumbing']);
        $this->assertTrue(Gate::forUser($manager)->allows('view', $complaintA));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $complaintB));

        $taskA = MaintenanceTask::create(['property_id' => $propertyA->id, 'title' => 'Fix tap', 'category' => 'plumbing']);
        $taskB = MaintenanceTask::create(['property_id' => $propertyB->id, 'title' => 'Fix tap', 'category' => 'plumbing']);
        $this->assertTrue(Gate::forUser($manager)->allows('view', $taskA));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $taskB));

        $scheduleA = MaintenanceSchedule::create(['property_id' => $propertyA->id, 'title' => 'Pest control', 'category' => 'pest', 'frequency_months' => 3, 'next_due_date' => now()->addMonth()]);
        $scheduleB = MaintenanceSchedule::create(['property_id' => $propertyB->id, 'title' => 'Pest control', 'category' => 'pest', 'frequency_months' => 3, 'next_due_date' => now()->addMonth()]);
        $this->assertTrue(Gate::forUser($manager)->allows('view', $scheduleA));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $scheduleB));

        $applicationA = Application::create(['desired_property_id' => $propertyA->id, 'applicant_name' => 'Jane', 'applicant_phone' => '0700000000', 'application_date' => now()]);
        $applicationB = Application::create(['desired_property_id' => $propertyB->id, 'applicant_name' => 'Jane', 'applicant_phone' => '0700000000', 'application_date' => now()]);
        $this->actingAs($manager)->get(route('manage.applications.show', $applicationA))->assertOk();
        $this->actingAs($manager)->get(route('manage.applications.show', $applicationB))->assertForbidden();
    }

    public function test_manager_with_all_properties_can_access_every_property(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $properties = $landlord->properties()->get();
        $this->assertGreaterThanOrEqual(2, $properties->count());

        $manager = $this->makeManagerUser($landlord->id, 'allaccess-manager@test.test', true);

        foreach ($properties as $property) {
            $this->actingAs($manager)->get(route('manage.properties.show', $property))->assertOk();
        }
    }

    public function test_a_manager_from_a_different_landlord_cannot_access_this_landlords_property(): void
    {
        $this->seed();

        $property = Property::firstOrFail();

        $otherLandlord = User::create([
            'name' => 'Other Landlord',
            'email' => 'other-landlord@test.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $otherLandlord->assignRole('landlord');

        $manager = $this->makeManagerUser($otherLandlord->id, 'foreign-manager@test.test', true);

        $this->actingAs($manager)->get(route('manage.properties.show', $property))->assertForbidden();
    }

    public function test_deactivated_manager_is_logged_out_and_blocked(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();
        $manager = $this->makeManagerUser($landlord->id, 'deactivated-manager@test.test', true, $property);

        // Simulates what the (not-yet-built) landlord-side "deactivate manager"
        // action will do: flip both the Manager row and the linked User's status.
        $manager->manager->update(['status' => 'inactive']);
        $manager->update(['status' => 'inactive']);

        $this->actingAs($manager)
            ->get(route('manage.properties.show', $property))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
