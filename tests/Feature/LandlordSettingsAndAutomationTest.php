<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\House;
use App\Models\LandlordSetting;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordSettingsAndAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_save_payment_gateway_settings(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->set('mpesa_shortcode_type', 'paybill')
            ->set('mpesa_shortcode', '400200')
            ->set('mpesa_consumer_key', 'key-123')
            ->set('mpesa_consumer_secret', 'secret-456')
            ->set('mpesa_passkey', 'passkey-789')
            ->call('saveGateway')
            ->assertHasNoErrors();

        $this->assertEquals('400200', LandlordSetting::get($landlord->id, 'mpesa_shortcode'));
        $this->assertEquals('key-123', LandlordSetting::getEncrypted($landlord->id, 'mpesa_consumer_key'));
        $this->assertTrue(app(PaymentGatewayService::class)->isConfigured($landlord->id));
    }

    public function test_landlord_can_save_rent_reminder_schedule(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(SettingsIndex::class)
            ->set('tab', 'reminders')
            ->set('reminder_days_before', '5,2')
            ->set('reminder_days_after', '2,10')
            ->set('reminder_channels', ['in_app', 'email'])
            ->call('saveReminders')
            ->assertHasNoErrors();

        $this->assertEquals('5,2', LandlordSetting::get($landlord->id, 'reminder_days_before'));
        $this->assertEquals('in_app,email', LandlordSetting::get($landlord->id, 'reminder_channels'));
    }

    public function test_rent_reminder_command_notifies_once_and_is_idempotent(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property')->firstOrFail();

        $charge = RentCharge::create([
            'tenancy_id' => $tenancy->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'due_date' => now()->toDateString(),
            'amount_expected' => $tenancy->monthly_rent,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        $this->artisan('notifications:rent-reminders')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'related_type' => $charge->getMorphClass(),
            'related_id' => $charge->id,
            'event_type' => 'rent_due_today',
        ]);
        $this->assertEquals(1, \App\Models\AppNotification::where('related_id', $charge->id)->where('event_type', 'rent_due_today')->count());

        // Running it again on the same day must not create a duplicate.
        $this->artisan('notifications:rent-reminders')->assertSuccessful();
        $this->assertEquals(1, \App\Models\AppNotification::where('related_id', $charge->id)->where('event_type', 'rent_due_today')->count());
    }

    public function test_contract_expiry_command_notifies_the_landlord_at_a_milestone(): void
    {
        $this->seed();

        $tenancy = Tenancy::active()->with('house.block.property.landlord')->firstOrFail();
        $tenancy->update(['lease_end_date' => now()->addDays(30)->toDateString()]);

        $landlord = $tenancy->house->block->property->landlord;

        $this->artisan('notifications:contract-expiry')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $landlord->getMorphClass(),
            'notifiable_id' => $landlord->id,
            'event_type' => 'contract_expiry_30',
        ]);

        // The tenant is notified too (in their own phrasing), alongside the landlord.
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $tenancy->tenant->getMorphClass(),
            'notifiable_id' => $tenancy->tenant->id,
            'event_type' => 'contract_expiry_30',
        ]);
        $this->assertEquals(2, \App\Models\AppNotification::where('related_id', $tenancy->id)->where('event_type', 'contract_expiry_30')->count());

        $this->artisan('notifications:contract-expiry')->assertSuccessful();
        $this->assertEquals(2, \App\Models\AppNotification::where('related_id', $tenancy->id)->where('event_type', 'contract_expiry_30')->count());
    }

    public function test_recurring_maintenance_command_generates_a_task_and_is_idempotent(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = $landlord->properties()->firstOrFail();

        $schedule = MaintenanceSchedule::create([
            'property_id' => $property->id,
            'title' => 'Fire Extinguisher Check',
            'category' => 'safety',
            'frequency_months' => 6,
            'next_due_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->artisan('maintenance:generate-recurring-tasks')->assertSuccessful();

        $this->assertDatabaseHas('maintenance_tasks', [
            'maintenance_schedule_id' => $schedule->id,
            'title' => 'Fire Extinguisher Check',
            'status' => 'submitted',
        ]);
        $this->assertEquals(1, MaintenanceTask::where('maintenance_schedule_id', $schedule->id)->count());

        $schedule->refresh();
        $this->assertTrue($schedule->next_due_date->isFuture());

        // Second run: schedule is no longer due, so nothing new should be created.
        $this->artisan('maintenance:generate-recurring-tasks')->assertSuccessful();
        $this->assertEquals(1, MaintenanceTask::where('maintenance_schedule_id', $schedule->id)->count());
    }
}
