<?php

namespace Tests\Feature;

use App\Livewire\ContractTemplates\Form as ContractTemplateForm;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Tenancies\Show as TenancyShow;
use App\Livewire\Tenant\Contract as TenantContract;
use App\Models\ContractTemplate;
use App\Models\House;
use App\Models\LandlordSetting;
use App\Models\Manager;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ContractModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeDefaultTemplate(User $landlord): ContractTemplate
    {
        return ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Standard Agreement',
            'body' => "Tenant: {{tenant_name}}\nProperty: {{property_name}}\nUnit: {{unit_number}}\nRent: {{monthly_rent}}",
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function createTenancyFor(User $landlord): \App\Models\Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Contract Test Tenant',
            'phone' => '0700111222',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_tenancy_creation_auto_generates_a_contract_when_a_default_template_exists(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);

        $tenancy = $this->createTenancyFor($landlord);

        $contract = $tenancy->contract;
        $this->assertNotNull($contract);
        $this->assertEquals('generated', $contract->status);
        $this->assertEquals(1, $contract->version);
        $this->assertNotNull($contract->document);
        $this->assertEquals('lease_agreement', $contract->document->category);
        $this->assertStringContainsString($tenancy->tenant->name, $contract->body_snapshot);
    }

    public function test_tenancy_creation_without_a_template_does_not_fail(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $this->assertNull($tenancy->contract);
    }

    public function test_landlord_can_send_a_generated_contract_to_the_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('sendContract')
            ->assertHasNoErrors();

        $contract = $tenancy->contract->fresh();
        $this->assertEquals('awaiting_signature', $contract->status);
        $this->assertNotNull($contract->sent_at);

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => (new Tenant)->getMorphClass(),
            'notifiable_id' => $tenancy->tenant_id,
            'event_type' => 'contract_sent',
        ]);
    }

    public function test_tenant_can_sign_an_awaiting_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'contract-tenant@example.test', 'password'
        );

        app(\App\Services\ContractService::class)->markSent($tenancy->contract);

        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'Contract Test Tenant')
            ->set('idDocument', UploadedFile::fake()->image('id.jpg'))
            ->call('sign')
            ->assertHasNoErrors();

        $contract = $tenancy->contract->fresh();
        $this->assertEquals('signed', $contract->status);
        $this->assertEquals('Contract Test Tenant', $contract->signed_by_name);
        $this->assertNotNull($contract->signed_at);
        $this->assertNotNull($contract->signed_ip);
    }

    public function test_signing_without_checking_the_agreement_box_fails_validation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'contract-tenant2@example.test', 'password'
        );

        app(\App\Services\ContractService::class)->markSent($tenancy->contract);

        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', false)
            ->set('typedFullName', 'Contract Test Tenant')
            ->call('sign')
            ->assertHasErrors('agreedToTerms');

        $this->assertEquals('awaiting_signature', $tenancy->contract->fresh()->status);
    }

    public function test_tenant_cannot_sign_a_contract_that_is_not_awaiting_signature(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'contract-tenant3@example.test', 'password'
        );

        // Contract is still 'generated', never sent.
        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'Contract Test Tenant')
            ->call('sign')
            ->assertForbidden();

        $this->assertEquals('generated', $tenancy->contract->fresh()->status);
    }

    public function test_regenerating_a_contract_bumps_the_version_and_keeps_the_old_row(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('generateContract')
            ->assertHasNoErrors();

        // refresh() clears the relation cache — Livewire's initial mount+render
        // in the test above ran on this same $tenancy object and already
        // cached ->contract pointing at version 1, before generateContract()
        // created version 2. A real page load wouldn't share this object.
        $tenancy->refresh();

        $this->assertEquals(2, $tenancy->contracts()->count());
        $this->assertEquals(2, $tenancy->contract->version);
    }

    public function test_contract_template_default_flag_is_exclusive_per_landlord(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $first = $this->makeDefaultTemplate($landlord);

        Livewire::actingAs($landlord)
            ->test(ContractTemplateForm::class)
            ->set('name', 'Second Template')
            ->set('body', 'Body text')
            ->set('is_default', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertEquals(1, ContractTemplate::where('landlord_id', $landlord->id)->where('is_default', true)->count());
    }

    /**
     * {{drawn_by_name}} is filled from Settings → Contract Letterhead, not
     * from anything on this form — a landlord filling in the template has
     * no reason to already know that, so a blank setting is flagged right
     * here with a direct link, not left to only ever show up as an
     * unexplained blank in a generated contract later.
     */
    public function test_template_form_warns_when_drawn_by_name_is_not_set_and_stops_once_it_is(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();

        $component = Livewire::actingAs($landlord)->test(ContractTemplateForm::class);
        $component->assertSee('will show up blank')
            ->assertSeeHtml('tab=contract_letterhead');

        LandlordSetting::set($landlord->id, 'contract_drawn_by_name', 'Jane Doe');

        Livewire::actingAs($landlord)->test(ContractTemplateForm::class)
            ->assertDontSee('will show up blank');
    }

    public function test_manager_without_tenancies_terminate_permission_cannot_send_a_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Manager',
            'phone' => '0700000000',
            'email' => 'contract-manager@example.test',
            'all_properties' => true,
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
        $managerUser->syncPermissions(['tenancies.view']);
        $manager->update(['user_id' => $managerUser->id]);

        Livewire::actingAs($managerUser->fresh())
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('sendContract')
            ->assertForbidden();

        $this->assertEquals('generated', $tenancy->contract->fresh()->status);
    }

    /**
     * Audit finding C6: sendContract/uploadContract/generateContract used
     * to be gated on tenancies.terminate (via TenancyPolicy::update()) —
     * the only permission ever checked, since no dedicated contracts
     * permission existed. A manager who could terminate a tenancy could
     * therefore also manage its contract, which was never the intent.
     * Decoupled into tenancies.manage_contracts; this proves terminate
     * alone no longer suffices.
     */
    public function test_a_manager_with_only_tenancies_terminate_can_no_longer_manage_contracts(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Terminate-Only Manager',
            'phone' => '0700000002',
            'email' => 'terminate-only-manager@example.test',
            'all_properties' => true,
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
        $managerUser->syncPermissions(['tenancies.view', 'tenancies.terminate']);
        $manager->update(['user_id' => $managerUser->id]);

        Livewire::actingAs($managerUser->fresh())
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('sendContract')
            ->assertForbidden();
    }

    public function test_a_manager_with_manage_contracts_permission_can_send_and_upload_a_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Contract Manager',
            'phone' => '0700000003',
            'email' => 'manage-contracts-manager@example.test',
            'all_properties' => true,
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
        $managerUser->syncPermissions(['tenancies.view', 'tenancies.manage_contracts']);
        $manager->update(['user_id' => $managerUser->id]);

        Livewire::actingAs($managerUser->fresh())
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->call('sendContract')
            ->assertHasNoErrors();

        $this->assertEquals('awaiting_signature', $tenancy->contract->fresh()->status);
    }

    public function test_landlord_can_upload_their_own_contract_for_a_tenancy(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('contractFile', UploadedFile::fake()->create('my-lease.pdf', 500, 'application/pdf'))
            ->call('uploadContract')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $contract = $tenancy->contract;
        $this->assertEquals('landlord_upload', $contract->source);
        $this->assertEquals('generated', $contract->status);
        $this->assertNull($contract->body_snapshot);
        $this->assertNotNull($contract->document);
        $this->assertEquals('my-lease.pdf', $contract->document->original_filename);
    }

    public function test_uploading_a_contract_bumps_the_version_and_keeps_prior_versions(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $this->makeDefaultTemplate($landlord);
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('contractFile', UploadedFile::fake()->create('my-lease.pdf', 500, 'application/pdf'))
            ->call('uploadContract')
            ->assertHasNoErrors();

        $tenancy->refresh();
        $this->assertEquals(2, $tenancy->contracts()->count());
        $this->assertEquals(2, $tenancy->contract->version);
        $this->assertEquals('landlord_upload', $tenancy->contract->source);
    }

    public function test_upload_rejects_non_pdf_files(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('contractFile', UploadedFile::fake()->create('my-lease.docx', 500))
            ->call('uploadContract')
            ->assertHasErrors('contractFile');
    }

    public function test_upload_rejects_oversized_files(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        Livewire::actingAs($landlord)
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('contractFile', UploadedFile::fake()->create('my-lease.pdf', 10241, 'application/pdf'))
            ->call('uploadContract')
            ->assertHasErrors('contractFile');
    }

    public function test_tenant_can_review_and_sign_an_uploaded_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        app(ContractService::class)->uploadForTenancy(
            $tenancy, UploadedFile::fake()->create('my-lease.pdf', 500, 'application/pdf'), $landlord->id
        );
        app(ContractService::class)->markSent($tenancy->contract);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenancy->tenant, $tenancy->tenant->name, 'upload-contract-tenant@example.test', 'password'
        );

        Livewire::actingAs($tenantUser)
            ->test(TenantContract::class)
            ->set('agreedToTerms', true)
            ->set('typedFullName', 'Contract Test Tenant')
            ->set('idDocument', UploadedFile::fake()->image('id.jpg'))
            ->call('sign')
            ->assertHasNoErrors();

        $contract = $tenancy->contract->fresh();
        $this->assertEquals('signed', $contract->status);
        $this->assertEquals('landlord_upload', $contract->source);
        $this->assertEquals('Contract Test Tenant', $contract->signed_by_name);
    }

    public function test_manager_without_tenancies_terminate_permission_cannot_upload_a_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Manager',
            'phone' => '0700000001',
            'email' => 'upload-manager@example.test',
            'all_properties' => true,
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
        $managerUser->syncPermissions(['tenancies.view']);
        $manager->update(['user_id' => $managerUser->id]);

        $countBefore = $tenancy->contracts()->count();

        Livewire::actingAs($managerUser->fresh())
            ->test(TenancyShow::class, ['tenancy' => $tenancy])
            ->set('contractFile', UploadedFile::fake()->create('my-lease.pdf', 500, 'application/pdf'))
            ->call('uploadContract')
            ->assertForbidden();

        $this->assertEquals($countBefore, $tenancy->contracts()->count());
    }

    public function test_landlord_can_save_contract_letterhead_settings(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->set('tab', 'contract_letterhead')
            ->set('contract_agency_name', 'Kats Agencies')
            ->set('contract_supervisor_name', 'Jane Supervisor')
            ->set('contract_supervisor_phone', '0700000002')
            ->set('contract_drawn_by_name', 'John Drafter')
            ->call('saveContractLetterhead')
            ->assertHasNoErrors();

        $this->assertEquals('Kats Agencies', LandlordSetting::get($landlord->id, 'contract_agency_name'));
        $this->assertEquals('Jane Supervisor', LandlordSetting::get($landlord->id, 'contract_supervisor_name'));
    }

    public function test_landlord_can_update_their_own_lessor_details_from_their_profile(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        $this->actingAs($landlord);

        Volt::test('profile.update-profile-information-form')
            ->set('name', $landlord->name)
            ->set('email', $landlord->email)
            ->set('phone', '0700000099')
            ->set('address', 'P.O. Box 123, Mombasa')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertEquals('0700000099', $landlord->fresh()->phone);
        $this->assertEquals('P.O. Box 123, Mombasa', LandlordSetting::get($landlord->id, 'contract_landlord_address'));
    }

    public function test_landlord_dashboard_prompts_to_complete_profile_when_phone_or_address_missing(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['phone' => null]);

        $this->actingAs($landlord)
            ->get(route('landlord.dashboard'))
            ->assertOk()
            ->assertSee('Complete Profile');
    }

    public function test_landlord_dashboard_hides_the_prompt_once_profile_is_complete(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['phone' => '0700000099']);
        LandlordSetting::set($landlord->id, 'contract_landlord_address', 'P.O. Box 123, Mombasa');

        $this->actingAs($landlord)
            ->get(route('landlord.dashboard'))
            ->assertOk()
            ->assertDontSee('Complete Profile');
    }

    public function test_new_placeholder_tokens_are_substituted_in_a_generated_contract(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        LandlordSetting::set($landlord->id, 'contract_agency_name', 'Kats Agencies');

        ContractTemplate::create([
            'landlord_id' => $landlord->id,
            'name' => 'Full Placeholder Template',
            'body' => "Landlord: {{landlord_name}}\nTenant ID: {{tenant_id_number}}\nAgency: {{agency_name}}",
            'is_default' => true,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name' => 'Placeholder Tenant',
            'phone' => '0700000003',
            'id_number' => '12345678',
            'payment_due_day' => 1,
        ]);

        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);

        $body = $tenancy->contract->body_snapshot;
        $this->assertStringContainsString($landlord->name, $body);
        $this->assertStringContainsString('12345678', $body);
        $this->assertStringContainsString('Kats Agencies', $body);
    }

    public function test_use_default_template_quick_fill_populates_the_body_field(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(ContractTemplateForm::class)
            ->call('useDefaultTemplate')
            ->assertSet('body', ContractTemplateForm::DEFAULT_TEMPLATE_BODY);
    }

    /**
     * Audit finding D1: ContractTemplate::scopeAccessibleBy() didn't apply
     * accessiblePropertyIds() filtering like every other model's version
     * does — currently harmless since ContractTemplatePolicy blocks every
     * manager outright, but fixed defensively at the model level too, in
     * case that policy is ever relaxed. A portfolio-wide template
     * (property_id null) must stay visible either way.
     */
    public function test_contract_template_scope_filters_by_accessible_properties(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $ownProperty = $landlord->properties()->firstOrFail();
        $otherProperty = \App\Models\Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Other Property',
            'address' => 'Elsewhere',
        ]);

        $portfolioWide = ContractTemplate::create([
            'landlord_id' => $landlord->id, 'property_id' => null,
            'name' => 'Portfolio Default', 'body' => 'x', 'is_default' => false, 'is_active' => true,
        ]);
        $ownPropertyTemplate = ContractTemplate::create([
            'landlord_id' => $landlord->id, 'property_id' => $ownProperty->id,
            'name' => 'Own Property Template', 'body' => 'x', 'is_default' => false, 'is_active' => true,
        ]);
        $otherPropertyTemplate = ContractTemplate::create([
            'landlord_id' => $landlord->id, 'property_id' => $otherProperty->id,
            'name' => 'Other Property Template', 'body' => 'x', 'is_default' => false, 'is_active' => true,
        ]);

        $manager = Manager::create([
            'landlord_id' => $landlord->id, 'name' => 'Scoped Manager', 'phone' => '0700000099',
            'email' => 'scoped-template-manager@example.test', 'all_properties' => false,
            'status' => 'active', 'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7), 'invited_at' => now(), 'activated_at' => now(),
        ]);
        $managerUser = User::create(['name' => $manager->name, 'email' => $manager->email, 'password' => bcrypt('password'), 'status' => 'active']);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);
        $manager->properties()->attach($ownProperty->id);

        $visibleIds = ContractTemplate::accessibleBy($managerUser->fresh())->pluck('id')->all();

        $this->assertContains($portfolioWide->id, $visibleIds);
        $this->assertContains($ownPropertyTemplate->id, $visibleIds);
        $this->assertNotContains($otherPropertyTemplate->id, $visibleIds);
    }
}
