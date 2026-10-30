<?php

namespace Tests\Feature;

use App\Livewire\Properties\Wizard;
use App\Models\DepositPayment;
use App\Models\Manager;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PropertyProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the property onboarding wizard (replaces the old Property -> Block
 * -> House three-form flow for property *creation*; editing an existing
 * property still uses the untouched Properties\Form). Each test name maps
 * directly to one of the required scenarios from the onboarding redesign
 * brief.
 */
class PropertyWizardTest extends TestCase
{
    use RefreshDatabase;

    private function completeStep1(?Testable $component = null, array $overrides = []): Testable
    {
        $landlord = User::role('landlord')->firstOrFail();

        $component ??= Livewire::actingAs($landlord)->test(Wizard::class);

        $component->set('type', $overrides['type'] ?? 'individual_house')
            ->set('name', $overrides['name'] ?? 'Test Property')
            ->set('address', $overrides['address'] ?? 'Nyali, Mombasa')
            ->call('goToStep2');

        return $component;
    }

    public function test_standalone_maisonette_wizard_never_exposes_a_block_step_and_auto_creates_one_block(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = Livewire::actingAs($landlord)->test(Wizard::class)
            ->set('type', 'maisonette')
            ->set('name', 'Solo Maisonette')
            ->call('goToStep2');

        // Picking a standalone-type property auto-selects the standalone
        // layout and locks it to exactly one unit — no block UI involved.
        $component->assertSet('layout', 'standalone')
            ->assertSet('groups.0.count', 1)
            ->assertDontSee('Number of blocks');

        $component->set('groups.0.unit_type', 'maisonette')
            ->set('groups.0.monthly_rent', '30000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'months')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $property = Property::where('name', 'Solo Maisonette')->firstOrFail();
        $property->load('blocks.houses');

        $this->assertCount(1, $property->blocks);
        $this->assertSame('Main', $property->blocks->first()->name);
        $this->assertCount(1, $property->blocks->first()->houses);
    }

    public function test_multiple_similar_layout_creates_five_villas_in_one_bulk_group(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = $this->completeStep1(overrides: ['type' => 'villas', 'name' => 'Five Villas']);

        $component->set('layout', 'multiple_similar')
            ->set('groups.0.unit_type', 'villa')
            ->set('groups.0.count', 5)
            ->set('groups.0.starting_number', '1')
            ->set('groups.0.monthly_rent', '45000')
            ->call('goToStep3')
            ->assertHasNoErrors()
            ->set('groups.0.deposit_mode', 'months')
            ->call('goToStep4')
            ->assertCount('reviewUnits', 5)
            ->call('save')
            ->assertHasNoErrors();

        $property = Property::where('name', 'Five Villas')->firstOrFail()->load('blocks.houses');

        $this->assertCount(1, $property->blocks);
        $this->assertCount(5, $property->blocks->first()->houses);
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4', '5'], $property->blocks->first()->houses->pluck('house_number')->all());
        $this->assertTrue($property->blocks->first()->houses->every(fn ($h) => $h->type === 'villa'));
    }

    public function test_blocks_layout_creates_multiple_blocks_with_different_unit_types_and_rents_per_block(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['type' => 'apartment', 'name' => 'Multi Block Apartments']);

        $component->set('layout', 'blocks')->set('block_count', 2);
        $component->set('groups.0.block_index', 0)
            ->set('groups.0.unit_type', 'bedsitter')
            ->set('groups.0.count', 10)
            ->set('groups.0.starting_number', '1')
            ->set('groups.0.monthly_rent', '8000');
        $component->call('addGroup');
        $component->set('groups.1.block_index', 1)
            ->set('groups.1.unit_type', 'two_bedroom')
            ->set('groups.1.count', 6)
            ->set('groups.1.starting_number', '1')
            ->set('groups.1.monthly_rent', '25000');

        $component->call('goToStep3')->assertHasNoErrors();
        $component->set('groups.0.deposit_mode', 'months')
            ->set('groups.1.deposit_mode', 'custom')
            ->set('groups.1.deposit_custom_amount', '50000')
            ->call('goToStep4')
            ->assertCount('reviewUnits', 16)
            ->call('save')
            ->assertHasNoErrors();

        $property = Property::where('name', 'Multi Block Apartments')->firstOrFail()->load('blocks.houses');

        $this->assertCount(2, $property->blocks);
        $this->assertEqualsCanonicalizing(['Block A', 'Block B'], $property->blocks->pluck('name')->all());

        $blockA = $property->blocks->firstWhere('name', 'Block A');
        $blockB = $property->blocks->firstWhere('name', 'Block B');

        $this->assertCount(10, $blockA->houses);
        $this->assertTrue($blockA->houses->every(fn ($h) => $h->type === 'bedsitter' && (float) $h->monthly_rent === 8000.0 && (float) $h->deposit_amount === 8000.0));

        $this->assertCount(6, $blockB->houses);
        $this->assertTrue($blockB->houses->every(fn ($h) => $h->type === 'two_bedroom' && (float) $h->monthly_rent === 25000.0 && (float) $h->deposit_amount === 50000.0));
    }

    public function test_deposit_mode_none_sets_zero_deposit_amount(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['name' => 'Deposit None']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $house = Property::where('name', 'Deposit None')->firstOrFail()->houses->first();
        $this->assertEquals(0, (float) $house->deposit_amount);
    }

    public function test_deposit_mode_months_with_one_month_sets_deposit_amount_equal_to_monthly_rent(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['name' => 'Deposit One Month']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'months')
            ->set('groups.0.deposit_months', 1)
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $house = Property::where('name', 'Deposit One Month')->firstOrFail()->houses->first();
        $this->assertEquals(20000, (float) $house->deposit_amount);
    }

    public function test_deposit_mode_months_multiplies_rent_by_the_selected_month_count(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['name' => 'Deposit Three Months']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'months')
            ->set('groups.0.deposit_months', 3)
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $house = Property::where('name', 'Deposit Three Months')->firstOrFail()->houses->first();
        $this->assertEquals(60000, (float) $house->deposit_amount);
    }

    public function test_deposit_mode_custom_amount_folds_the_note_into_description(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['name' => 'Deposit Custom Note']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'custom')
            ->set('groups.0.deposit_custom_amount', '10000')
            ->set('groups.0.deposit_note', 'Balance due within 30 days')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $house = Property::where('name', 'Deposit Custom Note')->firstOrFail()->houses->first();
        $this->assertEquals(10000, (float) $house->deposit_amount);
        $this->assertStringContainsString('Balance due within 30 days', $house->description);
    }

    public function test_deposit_mode_custom_sets_the_entered_amount(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['name' => 'Deposit Custom']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'custom')
            ->set('groups.0.deposit_custom_amount', '75000')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $house = Property::where('name', 'Deposit Custom')->firstOrFail()->houses->first();
        $this->assertEquals(75000, (float) $house->deposit_amount);
    }

    public function test_wizard_never_creates_a_deposit_payment_or_touches_tenancy_deposit_paid(): void
    {
        $this->seed();

        $depositPaymentsBefore = DepositPayment::count();
        $tenanciesBefore = Tenancy::count();

        $component = $this->completeStep1(overrides: ['type' => 'villas', 'name' => 'No Financial Side Effects']);
        $component->set('layout', 'multiple_similar')
            ->set('groups.0.unit_type', 'villa')
            ->set('groups.0.count', 3)
            ->set('groups.0.monthly_rent', '40000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'months')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($depositPaymentsBefore, DepositPayment::count(), 'Property onboarding must never create a DepositPayment.');
        $this->assertSame($tenanciesBefore, Tenancy::count(), 'Property onboarding must never create a Tenancy.');
    }

    public function test_newly_created_units_default_to_vacant_status(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['type' => 'villas', 'name' => 'Always Vacant']);
        $component->set('layout', 'multiple_similar')
            ->set('groups.0.unit_type', 'villa')
            ->set('groups.0.count', 3)
            ->set('groups.0.monthly_rent', '40000')
            ->call('goToStep3')
            ->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')
            ->call('save')
            ->assertHasNoErrors();

        $houses = Property::where('name', 'Always Vacant')->firstOrFail()->houses;
        $this->assertTrue($houses->every(fn ($h) => $h->status === 'vacant'));
    }

    public function test_generated_house_numbers_are_unique_within_their_block(): void
    {
        $this->seed();

        $component = $this->completeStep1(overrides: ['type' => 'apartment', 'name' => 'Unique Numbers']);
        $component->set('layout', 'blocks')->set('block_count', 1);
        $component->set('groups.0.block_index', 0)->set('groups.0.unit_type', 'bedsitter')->set('groups.0.count', 3)->set('groups.0.starting_number', '1')->set('groups.0.monthly_rent', '8000');
        $component->call('addGroup');
        // A second group in the SAME block — its default starting number
        // must continue from where the first group left off (4), not
        // restart at 1 and collide.
        $component->set('groups.1.block_index', 0)->assertSet('groups.1.starting_number', '4');
        $component->set('groups.1.unit_type', 'studio')->set('groups.1.count', 2)->set('groups.1.monthly_rent', '9000');

        $component->call('goToStep3')->set('groups.0.deposit_mode', 'none')->set('groups.1.deposit_mode', 'none')->call('goToStep4')->call('save')->assertHasNoErrors();

        $numbers = Property::where('name', 'Unique Numbers')->firstOrFail()->houses->pluck('house_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count(), 'House numbers within one block must be unique.');
    }

    public function test_save_then_skip_tenants_dismisses_the_onboarding_prompt(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = $this->completeStep1(overrides: ['name' => 'Skip Tenants']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')->call('save');

        $property = Property::where('name', 'Skip Tenants')->firstOrFail();

        // The wizard's redirect carries ?onboarded=1 — the banner shows once, on that landing.
        $response = $this->actingAs($landlord)->get(route('manage.properties.show', ['property' => $property, 'onboarded' => 1]));
        $response->assertSee('Would you like to add tenants now');

        // A later, ordinary visit (no query param) must not show it again.
        $response2 = $this->actingAs($landlord)->get(route('manage.properties.show', $property));
        $response2->assertDontSee('Would you like to add tenants now');
    }

    public function test_save_then_add_tenant_now_links_to_the_existing_tenancy_form_with_house_preselected(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = $this->completeStep1(overrides: ['name' => 'Add Tenant Now']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')->call('save');

        $property = Property::where('name', 'Add Tenant Now')->firstOrFail();
        $house = $property->houses->first();

        $response = $this->actingAs($landlord)->get(route('manage.properties.show', ['property' => $property, 'onboarded' => 1]));
        $response->assertSee(route('manage.tenancies.create', ['house_id' => $house->id]), false);
    }

    public function test_save_then_invite_tenant_links_to_the_existing_invitation_flow(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = $this->completeStep1(overrides: ['name' => 'Invite Tenant']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4')->call('save');

        $property = Property::where('name', 'Invite Tenant')->firstOrFail();

        $response = $this->actingAs($landlord)->get(route('manage.properties.show', ['property' => $property, 'onboarded' => 1]));
        $response->assertSee(route('manage.tenant-invitations.create'), false);
    }

    public function test_duplicate_submission_does_not_duplicate_property_records(): void
    {
        $this->seed();

        $countBefore = Property::count();

        $component = $this->completeStep1(overrides: ['name' => 'No Double Submit']);
        $component->set('groups.0.unit_type', 'house')->set('groups.0.monthly_rent', '20000')
            ->call('goToStep3')->set('groups.0.deposit_mode', 'none')
            ->call('goToStep4');

        // Simulate a double-click: call save() twice on the same mounted
        // component instance without a fresh page load in between.
        $component->call('save');
        $component->call('save');

        $this->assertSame($countBefore + 1, Property::count());
    }

    public function test_failed_mid_save_does_not_leave_partial_records(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $propertyCountBefore = Property::count();

        $propertyData = ['landlord_id' => $landlord->id, 'name' => 'Rollback Check', 'type' => 'other', 'address' => null, 'photo_path' => null];

        // Two units sharing the same house_number within the same block —
        // bypasses the component's own pre-save duplicate check by calling
        // the service directly, to prove the DB-level unique constraint
        // failure rolls back the whole transaction, not just that one row.
        $reviewUnits = [
            ['block_index' => 0, 'house_number' => '1', 'type' => 'house', 'bedrooms' => null, 'monthly_rent' => '10000', 'deposit_amount' => '0', 'electricity_arrangement' => 'shared', 'water_arrangement' => 'shared', 'description' => null],
            ['block_index' => 0, 'house_number' => '1', 'type' => 'house', 'bedrooms' => null, 'monthly_rent' => '10000', 'deposit_amount' => '0', 'electricity_arrangement' => 'shared', 'water_arrangement' => 'shared', 'description' => null],
        ];

        try {
            app(PropertyProvisioningService::class)->provision($propertyData, 'standalone', 1, $reviewUnits);
            $this->fail('Expected a unique-constraint violation.');
        } catch (QueryException $e) {
            // Expected — the duplicate house_number within one block violates houses' unique(block_id, house_number).
        }

        $this->assertSame($propertyCountBefore, Property::count(), 'A failed provision() must roll back the Property it created too.');
    }

    public function test_wizard_requires_property_create_authorization(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'No Permission Manager',
            'phone' => '0700000321',
            'email' => 'no-create-perm@test.test',
            'all_properties' => true,
            'permissions' => [],
            'status' => 'active',
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);
        $managerUser = User::create(['name' => $manager->name, 'email' => $manager->email, 'password' => bcrypt('password'), 'status' => 'active']);
        $managerUser->assignRole('manager');
        $managerUser->syncPermissions([]);
        $manager->update(['user_id' => $managerUser->id]);

        Livewire::actingAs($managerUser)->test(Wizard::class)->assertStatus(403);
    }
}
