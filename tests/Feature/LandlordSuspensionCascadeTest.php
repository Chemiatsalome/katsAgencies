<?php

namespace Tests\Feature;

use App\Livewire\Admin\Landlords\Index as LandlordsIndex;
use App\Models\AppNotification;
use App\Models\House;
use App\Models\Manager;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentGatewayService;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 (account lifecycle), point A/B/G/H: what happens to a landlord's
 * managers, tenants and rent collection when an admin suspends or
 * reinstates them, and that an independently-restricted manager/tenant
 * relationship is never swept up by the cascade.
 */
class LandlordSuspensionCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(User $landlord, string $slug): User
    {
        $manager = Manager::create([
            'landlord_id' => $landlord->id, 'name' => "Cascade Manager {$slug}",
            'phone' => '07133'.substr(md5($slug), 0, 5), 'email' => "cascade-manager-{$slug}@example.test",
            'all_properties' => true, 'status' => 'active',
            'token' => Manager::generateToken(), 'expires_at' => now()->addDays(7),
            'invited_at' => now(), 'activated_at' => now(),
        ]);
        $managerUser = User::create([
            'name' => $manager->name, 'email' => $manager->email,
            'password' => bcrypt('password'), 'status' => 'active',
        ]);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);

        return $managerUser->fresh();
    }

    private function makeTenancyFor(User $landlord, string $slug): \App\Models\Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create(['name' => "Cascade Tenant {$slug}", 'phone' => '0701'.substr(md5($slug), 0, 6), 'payment_due_day' => 1]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id, 'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent, 'deposit_required' => $house->deposit_amount, 'rent_due_day' => 1,
        ]);
    }

    public function test_suspending_a_landlord_suspends_their_active_managers_and_notifies_tenants(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, 'a');
        $tenancy = $this->makeTenancyFor($landlord, 'a');

        Livewire::actingAs($admin)
            ->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Repeated tenant complaints under review.')
            ->call('confirmStatusChange');

        $this->assertEquals('suspended', $landlord->fresh()->status);
        $this->assertEquals('inactive', $managerUser->manager->fresh()->status);
        $this->assertEquals('inactive', $managerUser->fresh()->status);
        $this->assertNotNull($managerUser->manager->fresh()->suspended_via_landlord_suspension_at);

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $managerUser->getMorphClass(), 'notifiable_id' => $managerUser->id,
            'event_type' => 'manager_access_suspended_via_landlord',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $tenancy->tenant->getMorphClass(), 'notifiable_id' => $tenancy->tenant_id,
            'event_type' => 'landlord_suspended',
        ]);

        // Existing records untouched.
        $this->assertDatabaseHas('tenancies', ['id' => $tenancy->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenancy->tenant_id]);
    }

    public function test_rent_collection_is_disabled_for_a_suspended_landlords_tenant(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $landlord->update(['status' => 'suspended']);

        $this->expectException(\RuntimeException::class);

        app(PaymentGatewayService::class)->initiateStkPush($landlord->id, '254712345678', 1000, 'Rent-1', 'Rent payment');
    }

    public function test_a_tenants_current_tenancy_landlord_suspended_check_reflects_suspension(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'b');

        $this->assertFalse($tenancy->tenant->currentTenancyLandlordIsSuspended());

        $landlord->update(['status' => 'suspended']);

        $this->assertTrue($tenancy->tenant->fresh()->currentTenancyLandlordIsSuspended());
    }

    public function test_reinstating_a_landlord_restores_only_inherited_manager_suspensions(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();
        $inheritedManager = $this->makeManager($landlord, 'c');
        $independentManager = $this->makeManager($landlord, 'd');

        // Independently deactivated by the landlord BEFORE the suspension.
        $independentManager->manager->update(['status' => 'inactive']);
        $independentManager->update(['status' => 'inactive']);

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Testing reinstatement provenance.')
            ->call('confirmStatusChange');

        $this->assertEquals('inactive', $inheritedManager->fresh()->status);
        $this->assertNull($independentManager->manager->fresh()->suspended_via_landlord_suspension_at);

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('updateStatus', $landlord->id, 'active');

        $this->assertEquals('active', $inheritedManager->fresh()->status);
        $this->assertNull($inheritedManager->manager->fresh()->suspended_via_landlord_suspension_at);

        // The independently-suspended manager must remain untouched.
        $this->assertEquals('inactive', $independentManager->fresh()->status);
        $this->assertEquals('inactive', $independentManager->manager->fresh()->status);
    }

    public function test_suspending_one_landlord_does_not_affect_an_unrelated_landlord_or_their_managers(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $otherLandlord = User::factory()->create(['status' => 'active']);
        $otherLandlord->assignRole('landlord');
        $otherManager = $this->makeManager($otherLandlord, 'e');

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Testing isolation.')
            ->call('confirmStatusChange');

        $this->assertEquals('active', $otherLandlord->fresh()->status);
        $this->assertEquals('active', $otherManager->fresh()->status);
    }

    // --- Part B: suspension communication ------------------------------------------------

    public function test_the_landlords_own_notification_contains_the_actual_reason_and_configured_contact(): void
    {
        $this->seed();
        \App\Models\Setting::set('org_contact_email', 'admin@katsagencies.com');

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Multiple unresolved tenant deposit complaints.')
            ->call('confirmStatusChange');

        $notification = AppNotification::where('notifiable_type', $landlord->getMorphClass())
            ->where('notifiable_id', $landlord->id)->where('event_type', 'landlord_status_changed')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Multiple unresolved tenant deposit complaints.', $notification->body);
        $this->assertStringContainsString('admin@katsagencies.com', $notification->body);
        $this->assertStringNotContainsString('no-reply@katsagencies.com', $notification->body);
    }

    /**
     * B2 correction: a real, previously-undiscovered bug — Setting::get()
     * caches forever (Cache::rememberForever), so a bare diagnostic read
     * with a throwaway default permanently poisons every future read of
     * that key app-wide until the cache entry is explicitly cleared.
     * Setting::set() always clears it first, which is what keeps this
     * test (and the real fix applied to the dev database) safe.
     */
    public function test_setting_the_contact_email_clears_any_previously_cached_value(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Cache::rememberForever('setting:org_contact_email', fn () => '(not set)');
        $this->assertEquals('(not set)', \App\Models\Setting::get('org_contact_email'));

        \App\Models\Setting::set('org_contact_email', 'admin@katsagencies.com');

        $this->assertEquals('admin@katsagencies.com', \App\Models\Setting::get('org_contact_email'));
    }

    public function test_an_inherited_manager_notification_makes_clear_it_is_not_an_independent_suspension(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();
        $managerUser = $this->makeManager($landlord, 'i');

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Compliance review.')
            ->call('confirmStatusChange');

        $notification = AppNotification::where('notifiable_type', $managerUser->getMorphClass())
            ->where('notifiable_id', $managerUser->id)->where('event_type', 'manager_access_suspended_via_landlord')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('not an independent action against you', $notification->body);
        $this->assertStringContainsString('Compliance review.', $notification->body);

        $activity = \Spatie\Activitylog\Models\Activity::where('subject_type', Manager::class)
            ->where('subject_id', $managerUser->manager->id)->where('event', 'updated')->latest('id')->first();
        $this->assertStringContainsString('Inherited', $activity->properties['reason'] ?? '');
    }

    public function test_the_tenant_notice_explicitly_states_tenancy_and_rent_obligations_remain_in_force(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->makeTenancyFor($landlord, 'j');

        Livewire::actingAs($admin)->test(LandlordsIndex::class)
            ->call('startStatusChange', $landlord->id, 'suspended')
            ->set('reason', 'Internal review.')
            ->call('confirmStatusChange');

        $notification = AppNotification::where('notifiable_type', $tenancy->tenant->getMorphClass())
            ->where('notifiable_id', $tenancy->tenant_id)->where('event_type', 'landlord_suspended')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('does not terminate your tenancy', $notification->body);
        $this->assertStringContainsString('does not cancel your rent', $notification->body);
        $this->assertStringNotContainsString('Internal review.', $notification->body, 'The tenant must not see the admin\'s internal suspension reason.');
    }

    public function test_account_closure_blockers_still_work_alongside_the_suspension_cascade(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $this->makeTenancyFor($landlord, 'k');

        $blockers = app(\App\Services\AccountClosureService::class)->closeLandlordAccount($landlord);

        $this->assertNotEmpty($blockers);
        $this->assertEquals('active', $landlord->fresh()->status);
    }
}
