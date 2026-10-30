<?php

namespace Tests\Feature;

use App\Livewire\Admin\Landlords\Index as LandlordsIndex;
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

    public function test_manager_delegation_permissions_cannot_be_toggled_at_the_role_level(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        // Granting this to the `manager` role would silently hand it to
        // every manager on the platform, overriding each landlord's own
        // per-manager delegation choices — must be blocked entirely here.
        Livewire::actingAs($admin)
            ->test(PermissionsIndex::class)
            ->call('toggle', 'manager', 'tenancies.terminate')
            ->assertStatus(422);

        $this->assertFalse(\Spatie\Permission\Models\Role::findByName('manager')->hasPermissionTo('tenancies.terminate'));
    }

    public function test_manager_delegation_group_is_not_shown_on_the_permissions_matrix(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(PermissionsIndex::class)
            ->assertDontSee('Manager Delegation');
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

    public function test_admin_landlords_index_rejects_an_unknown_status_value(): void
    {
        $this->seed();

        $admin = User::role('admin')->firstOrFail();
        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(LandlordsIndex::class)
            ->call('updateStatus', $landlord->id, 'not-a-real-status')
            ->assertStatus(422);

        $this->assertEquals('active', $landlord->fresh()->status);
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

    /**
     * Previously the platform's own M-Pesa paybill credentials existed
     * only in .env — no admin panel controlled them at all. Now editable
     * live from this Payments tab, with PaymentGatewayService checking the
     * database first (see platformSetting()) and falling back to .env
     * only when nothing has been saved here yet.
     */
    public function test_admin_can_save_platform_mpesa_settings_and_they_take_effect_immediately(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        config(['services.mpesa.shortcode' => null, 'services.mpesa.passkey' => null]);
        $this->assertFalse(app(\App\Services\PaymentGatewayService::class)->isPlatformConfigured());

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->set('mpesa_env', 'sandbox')
            ->set('mpesa_consumer_key', 'test-consumer-key')
            ->set('mpesa_consumer_secret', 'test-consumer-secret')
            ->set('mpesa_shortcode', '174379')
            ->set('mpesa_callback_url', 'https://kats.example.test/mpesa/callback')
            ->set('mpesa_passkey', 'test-passkey')
            ->set('mpesa_callback_secret', str_repeat('a', 40))
            ->call('savePayments')
            ->assertHasNoErrors();

        $this->assertEquals('174379', Setting::get('mpesa_shortcode'));
        $this->assertEquals('test-passkey', Setting::getEncrypted('mpesa_passkey'));
        $this->assertTrue(app(\App\Services\PaymentGatewayService::class)->isPlatformConfigured());
    }

    public function test_leaving_the_secret_fields_blank_on_resave_keeps_the_existing_values(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        Setting::set('mpesa_shortcode', '174379');
        Setting::setEncrypted('mpesa_passkey', 'original-passkey');
        Setting::setEncrypted('mpesa_consumer_secret', 'original-consumer-secret');
        Setting::setEncrypted('mpesa_callback_secret', str_repeat('b', 40));

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->set('mpesa_consumer_key', 'test-consumer-key')
            ->set('mpesa_shortcode', '174379')
            ->set('mpesa_callback_url', 'https://kats.example.test/mpesa/callback')
            // consumer_secret and passkey left blank deliberately
            ->call('savePayments')
            ->assertHasNoErrors();

        $this->assertEquals('original-passkey', Setting::getEncrypted('mpesa_passkey'));
        $this->assertEquals('original-consumer-secret', Setting::getEncrypted('mpesa_consumer_secret'));
    }

    public function test_generate_callback_secret_produces_a_new_random_value(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        $component = Livewire::actingAs($admin)->test(SettingsIndex::class);
        $component->call('generateCallbackSecret');
        $first = $component->get('mpesa_callback_secret');

        $component->call('generateCallbackSecret');
        $second = $component->get('mpesa_callback_secret');

        $this->assertEquals(40, strlen($first));
        $this->assertNotEquals($first, $second);
    }

    public function test_test_connection_reports_a_clear_error_when_safaricom_rejects_the_credentials(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        Setting::set('mpesa_shortcode', '174379');
        Setting::set('mpesa_consumer_key', 'bad-key');
        Setting::setEncrypted('mpesa_consumer_secret', 'bad-secret');

        \Illuminate\Support\Facades\Http::fake([
            '*/oauth/v1/generate*' => \Illuminate\Support\Facades\Http::response(['errorMessage' => 'Invalid Credentials'], 401),
        ]);

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->call('testConnection')
            ->assertHasErrors('mpesa_consumer_key');
    }
}
