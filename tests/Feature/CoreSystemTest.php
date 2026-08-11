<?php

namespace Tests\Feature;

use App\Livewire\Payments\RecordPayment;
use App\Livewire\Tenancies\Show;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class CoreSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboards_load_for_each_role(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $tenantModel = \App\Models\Tenant::where('name', 'Anne Murangiri')->firstOrFail();
        $tenantUser = User::create([
            'name' => $tenantModel->name,
            'email' => 'anne@example.test',
            'password' => Hash::make('password'),
        ]);
        $tenantUser->syncRoles(['tenant']);
        $tenantModel->update(['user_id' => $tenantUser->id]);

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('Platform Dashboard');
        $this->actingAs($landlord)->get('/landlord/dashboard')->assertOk()->assertSee('how your properties are performing');
        $this->actingAs($tenantUser)->get('/tenant/dashboard')->assertOk()->assertSee('Welcome');
        $this->actingAs($tenantUser)->get('/tenant/rent')->assertOk();

        $this->actingAs($landlord)->get('/manage/properties')->assertOk();
        $this->actingAs($landlord)->get('/manage/tenants')->assertOk();
        $this->actingAs($landlord)->get('/manage/tenancies')->assertOk();
        $this->actingAs($landlord)->get('/manage/arrears')->assertOk()->assertSee('Jane Anyango Sifuna');
        $this->actingAs($landlord)->get('/manage/rent-charges')->assertOk();
    }

    public function test_admin_and_landlord_route_scopes_do_not_overlap(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        // Kats Agencies admin oversees the platform and landlords, not day-to-day property/tenant/rent operations.
        $adminOnlyRoutes = [
            '/admin/landlords',
            '/admin/landlords/applications',
            '/admin/landlords/properties',
            '/admin/landlords/activity',
            '/admin/platform/overview',
            '/admin/platform/occupancy',
            '/admin/notifications',
            '/admin/reports/platform',
            '/admin/reports/landlord',
            '/admin/users',
            '/admin/permissions',
            '/admin/settings',
            '/admin/audit-logs',
        ];

        foreach ($adminOnlyRoutes as $route) {
            $this->actingAs($admin)->get($route)->assertOk();
            $this->actingAs($landlord)->get($route)->assertForbidden();
        }

        // Property/tenant/rent management is exclusively the landlord's domain.
        $landlordOnlyRoutes = [
            '/manage/properties',
            '/manage/units',
            '/manage/tenants',
            '/manage/tenancies',
            '/manage/rent-charges',
            '/manage/arrears',
        ];

        foreach ($landlordOnlyRoutes as $route) {
            $this->actingAs($landlord)->get($route)->assertOk();
            $this->actingAs($admin)->get($route)->assertForbidden();
        }
    }

    public function test_landlord_scoping_is_enforced_across_two_landlords(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $landlordA = User::role('landlord')->firstOrFail();

        $landlordB = User::create([
            'name' => 'Landlord B', 'email' => 'landlordb@katsagencies.test', 'password' => Hash::make('password'),
        ]);
        $landlordB->syncRoles(['landlord']);

        $propertyA = Property::create(['landlord_id' => $landlordA->id, 'name' => 'Property A', 'status' => 'active']);
        $propertyB = Property::create(['landlord_id' => $landlordB->id, 'name' => 'Property B', 'status' => 'active']);

        $indexAsA = $this->actingAs($landlordA)->get('/manage/properties');
        $indexAsA->assertOk()->assertSee('Property A')->assertDontSee('Property B');

        $this->actingAs($landlordA)->get('/manage/properties/'.$propertyB->id.'/edit')->assertForbidden();
        $this->actingAs($landlordB)->get('/manage/properties/'.$propertyA->id.'/edit')->assertForbidden();
    }

    public function test_partial_payments_accumulate_to_fully_paid(): void
    {
        $this->seed();

        $tenancy = Tenancy::whereHas('tenant', fn ($q) => $q->where('name', 'Jane Anyango Sifuna (Claudia Kubati)'))->firstOrFail();
        $charge = RentCharge::where('tenancy_id', $tenancy->id)->where('status', '!=', 'paid')->firstOrFail();
        $balance = (float) $charge->amount_expected - (float) $charge->amount_paid;
        $this->assertGreaterThan(0, $balance);

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(RecordPayment::class, ['tenancy' => $tenancy])
            ->set('rent_charge_id', $charge->id)
            ->set('amount', 5000)
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'mpesa')
            ->call('save')
            ->assertHasNoErrors();

        $charge->refresh();
        $this->assertEquals('partial', $charge->status);
        $this->assertEquals(5000, (float) $charge->amount_paid);

        $remaining = (float) $charge->amount_expected - (float) $charge->amount_paid;

        Livewire::actingAs($landlord)
            ->test(RecordPayment::class, ['tenancy' => $tenancy])
            ->set('rent_charge_id', $charge->id)
            ->set('amount', $remaining)
            ->set('payment_date', now()->format('Y-m-d'))
            ->set('method', 'mpesa')
            ->call('save')
            ->assertHasNoErrors();

        $charge->refresh();
        $this->assertEquals('paid', $charge->status);
        $this->assertEquals((float) $charge->amount_expected, (float) $charge->amount_paid);
    }

    public function test_rent_charge_generation_is_idempotent(): void
    {
        $this->seed();

        Artisan::call('rent:generate-charges', ['--year' => 2030, '--month' => 1]);
        $firstRun = RentCharge::where('period_year', 2030)->where('period_month', 1)->count();
        $this->assertGreaterThan(0, $firstRun);

        Artisan::call('rent:generate-charges', ['--year' => 2030, '--month' => 1]);
        $secondRun = RentCharge::where('period_year', 2030)->where('period_month', 1)->count();

        $this->assertEquals($firstRun, $secondRun);
    }

    public function test_move_out_vacates_the_house(): void
    {
        $this->seed();

        $tenancy = Tenancy::whereHas('tenant', fn ($q) => $q->where('name', 'Ochieng Kevin'))->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $this->assertEquals('occupied', $tenancy->house->status);

        Livewire::actingAs($landlord)
            ->test(Show::class, ['tenancy' => $tenancy])
            ->call('moveOut');

        $tenancy->refresh();
        $this->assertEquals('completed', $tenancy->status);
        $this->assertEquals('vacant', $tenancy->house->fresh()->status);
    }
}
