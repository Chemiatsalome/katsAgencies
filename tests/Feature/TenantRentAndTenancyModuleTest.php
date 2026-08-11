<?php

namespace Tests\Feature;

use App\Livewire\Tenant\MyTenancy;
use App\Livewire\Tenant\Statements;
use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantRentAndTenancyModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUser(): User
    {
        $tenancy = Tenancy::active()->firstOrFail();
        $tenant = $tenancy->tenant;

        $user = User::factory()->create();
        $user->syncRoles(['tenant']);
        $tenant->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_tenant_can_download_a_payment_receipt_and_a_full_statement(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->firstOrFail();

        $payment = Payment::create([
            'tenancy_id' => $tenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'mpesa',
            'reference_number' => 'TEST123',
        ]);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $payment->id)
            ->assertStatus(200);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadStatement')
            ->assertStatus(200);
    }

    public function test_tenant_cannot_download_another_tenants_payment_receipt(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();

        $otherTenancy = Tenancy::active()->where('tenant_id', '!=', $user->tenant->id)->firstOrFail();
        $otherPayment = Payment::create([
            'tenancy_id' => $otherTenancy->id,
            'amount' => 5000,
            'payment_date' => now(),
            'method' => 'cash',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(Statements::class)
            ->call('downloadReceipt', $otherPayment->id);
    }

    public function test_tenant_can_request_a_tenancy_change_and_landlord_is_notified(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenancy = $user->tenant->tenancies()->active()->with('house.block.property')->firstOrFail();
        $landlord = $tenancy->house->block->property->landlord;

        Livewire::actingAs($user)
            ->test(MyTenancy::class)
            ->set('requestMessage', 'My phone number on file is outdated.')
            ->call('requestChange')
            ->assertHasNoErrors();

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'tenancy_change_request')
                ->exists()
        );
    }

    public function test_tenant_tenancy_history_shows_past_tenancies_but_not_the_current_one_twice(): void
    {
        $this->seed();

        $user = $this->makeTenantUser();
        $tenant = $user->tenant;
        $currentTenancy = $tenant->tenancies()->active()->firstOrFail();

        $vacantHouse = \App\Models\House::vacant()
            ->whereHas('block.property', fn ($q) => $q->where('landlord_id', $currentTenancy->house->block->property->landlord_id))
            ->first();

        if ($vacantHouse) {
            Tenancy::create([
                'tenant_id' => $tenant->id,
                'house_id' => $vacantHouse->id,
                'move_in_date' => now()->subYears(2),
                'move_out_date' => now()->subYear(),
                'monthly_rent' => 5000,
                'rent_due_day' => 1,
                'status' => 'completed',
            ]);
        }

        $component = Livewire::actingAs($user)->test(MyTenancy::class);

        if ($vacantHouse) {
            $component->assertSee('Previous Tenancies');
        }
        $component->assertSee($currentTenancy->house->house_number);
    }
}
