<?php

namespace Tests\Feature;

use App\Livewire\Admin\Permissions\Index as PermissionsIndex;
use App\Livewire\Admin\Settings\Index as SettingsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_toggle_a_landlord_permission(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        $this->assertTrue($landlord->hasPermissionTo('properties.manage'));
        $this->assertFalse($landlord->hasPermissionTo('users.manage'));

        Livewire::actingAs($admin)
            ->test(PermissionsIndex::class)
            ->call('toggle', 'landlord', 'users.manage');

        $this->assertTrue($landlord->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_admin_can_change_a_users_role_and_status(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('updateStatus', $landlord->id, 'suspended');

        $this->assertEquals('suspended', $landlord->fresh()->status);
    }

    public function test_admin_can_save_general_settings(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->set('org_name', 'Kats Property Group')
            ->set('currency', 'USD')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $this->assertEquals('Kats Property Group', Setting::get('org_name'));
        $this->assertEquals('USD', Setting::get('currency'));
    }

    public function test_admin_can_add_an_expense_category(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->set('new_expense_category', 'Pest Control')
            ->call('addExpenseCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', ['name' => 'Pest Control', 'is_active' => true]);
    }
}
