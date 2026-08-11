<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandlordCommunicationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_message_a_single_tenant(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenancy->tenant_id)
            ->set('subject', 'Water shutdown notice')
            ->set('body', 'Water will be off tomorrow from 9am to 1pm for maintenance.')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $tenancy->tenant->getMorphClass(),
            'notifiable_id' => $tenancy->tenant_id,
            'event_type' => 'landlord_message',
            'title' => 'Water shutdown notice',
        ]);
    }

    public function test_landlord_can_message_all_tenants_in_a_property(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $property = Property::forLandlord($landlord->id)->whereHas('houses.tenancies', fn ($q) => $q->active())->firstOrFail();

        $expectedRecipients = Tenancy::active()
            ->whereHas('house.block', fn ($q) => $q->where('property_id', $property->id))
            ->distinct('tenant_id')
            ->count('tenant_id');

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'property')
            ->set('property_id', $property->id)
            ->set('subject', 'Rent increase notice')
            ->set('body', 'Rent will increase by 5% starting next quarter.')
            ->set('channels', ['in_app', 'email'])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('app_notifications', $expectedRecipients * 2);
    }

    public function test_sending_without_selecting_a_recipient_field_fails_validation(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('subject', 'Hello')
            ->set('body', 'Test')
            ->set('channels', ['in_app'])
            ->call('send')
            ->assertHasErrors('tenant_id');
    }

    public function test_notifications_index_only_shows_this_landlords_tenant_notifications(): void
    {
        $this->seed();

        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->active()->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenancy->tenant_id)
            ->set('subject', 'Test Notice')
            ->set('body', 'Body text')
            ->set('channels', ['in_app'])
            ->call('send');

        Livewire::actingAs($landlord)
            ->test(NotificationsIndex::class)
            ->assertSee('Test Notice');
    }
}
