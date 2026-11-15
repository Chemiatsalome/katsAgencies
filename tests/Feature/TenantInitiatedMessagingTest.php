<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Tenant\Announcements\Index as AnnouncementsIndex;
use App\Models\AppNotification;
use App\Models\House;
use App\Models\Manager;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F-2: a tenant could previously only reply to a conversation the landlord
 * or a manager had already opened — they had no way to initiate one of
 * their own. Covers tenant-initiated conversation creation, recipient
 * resolution/authorisation, notification routing through the existing
 * notifyPropertyStakeholders() architecture, and the security boundaries
 * around it. Builds on the Message/MessageReply thread model and reply
 * mechanics TwoWayMessagingTest already covers for the landlord-initiated
 * direction — those are not re-tested here.
 */
class TenantInitiatedMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenancyAndPortalUser(User $landlord, string $emailSlug): array
    {
        static $n = 0;
        $n++;

        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => "Initiating Tenant {$n}",
            'phone' => '07011122'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'payment_due_day' => 1,
        ]);

        $tenancy = app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);

        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount(
            $tenant, $tenant->name, "{$emailSlug}-{$n}@example.test", 'password'
        );

        return [$tenancy, $tenantUser];
    }

    private function makeManager(User $landlord, bool $allProperties, array $properties = [], string $status = 'active'): User
    {
        static $n = 0;
        $n++;

        $manager = Manager::create([
            'landlord_id' => $landlord->id,
            'name' => "Messaging Test Manager {$n}",
            'phone' => '07150000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "messaging-test-manager-{$n}@example.test",
            'all_properties' => $allProperties,
            'status' => $status,
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
            'invited_at' => now(),
            'activated_at' => now(),
        ]);

        $managerUser = User::create([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => bcrypt('password'),
            'status' => $status === 'active' ? 'active' : 'inactive',
        ]);
        $managerUser->assignRole('manager');
        $manager->update(['user_id' => $managerUser->id]);

        if (! $allProperties && $properties) {
            $manager->properties()->sync(collect($properties)->pluck('id'));
        }

        return $managerUser->fresh();
    }

    public function test_a_tenant_can_start_a_new_conversation_and_the_landlord_and_an_eligible_manager_are_notified_but_not_an_unrelated_manager(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'initiator');
        $property = $tenancy->house->block->property;

        $eligibleManager = $this->makeManager($landlord, allProperties: false, properties: [$property]);
        $otherLandlord = User::factory()->create();
        $otherLandlord->assignRole('landlord');
        $unrelatedManager = $this->makeManager($otherLandlord, allProperties: true);

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Leaking tap')
            ->set('newBody', 'The kitchen tap has been leaking for two days.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = Message::firstOrFail();
        $this->assertEquals($tenancy->tenant_id, $message->tenant_id);
        $this->assertEquals($landlord->id, $message->sender_id);
        $this->assertEquals('tenant_initiated', $message->scope);
        $this->assertEquals('Leaking tap', $message->subject);

        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id,
            'event_type' => 'tenant_message', 'related_type' => Message::class, 'related_id' => $message->id,
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $eligibleManager->id,
            'event_type' => 'tenant_message', 'related_type' => Message::class, 'related_id' => $message->id,
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $unrelatedManager->id,
            'event_type' => 'tenant_message',
        ]);
    }

    public function test_the_tenant_sees_their_own_new_conversation_immediately_with_no_reply_yet(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'immediate');

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Parking question')
            ->set('newBody', 'Is visitor parking available on weekends?')
            ->call('sendMessage');

        $component = Livewire::actingAs($tenantUser)->test(AnnouncementsIndex::class);
        $announcements = $component->viewData('announcements');

        $this->assertCount(1, $announcements);
        $this->assertEquals('Parking question', $announcements->first()->title);
        $this->assertNotNull($announcements->first()->read_at);
    }

    public function test_the_landlord_sees_the_tenant_initiated_conversation_in_their_messages_list(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'landlord-view');

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Noisy neighbour')
            ->set('newBody', 'Unit 4B has been very loud at night.')
            ->call('sendMessage');

        $component = Livewire::actingAs($landlord)->test(MessagesIndex::class);
        $history = $component->viewData('history');

        $this->assertCount(1, $history);
        $this->assertEquals('Noisy neighbour', $history->first()->title);
    }

    public function test_the_landlord_can_reply_to_a_tenant_initiated_conversation_and_the_tenant_is_notified(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'landlord-reply');

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Broken gate')
            ->set('newBody', 'The main gate lock is broken.')
            ->call('sendMessage');

        $message = Message::firstOrFail();
        $notificationId = AppNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $landlord->id)
            ->where('event_type', 'tenant_message')
            ->where('related_id', $message->id)
            ->value('id');

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->call('toggleExpand', $notificationId)
            ->set('replyBody', "We'll send someone over today.")
            ->call('reply', $notificationId)
            ->assertHasNoErrors();

        $this->assertEquals(1, $message->replies()->count());
        $this->assertEquals($landlord->id, $message->replies()->first()->user_id);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => Tenant::class, 'notifiable_id' => $message->tenant_id, 'event_type' => 'message_reply',
        ]);
    }

    public function test_an_eligible_manager_can_reply_to_a_tenant_initiated_conversation(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [$tenancy, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'manager-reply');
        $property = $tenancy->house->block->property;
        $manager = $this->makeManager($landlord, allProperties: false, properties: [$property]);

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Rent receipt')
            ->set('newBody', "I can't find last month's receipt.")
            ->call('sendMessage');

        $message = Message::firstOrFail();
        $notificationId = AppNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $manager->id)
            ->where('event_type', 'tenant_message')
            ->where('related_id', $message->id)
            ->value('id');

        Livewire::actingAs($manager)
            ->test(MessagesIndex::class)
            ->call('toggleExpand', $notificationId)
            ->set('replyBody', "I'll email it to you now.")
            ->call('reply', $notificationId)
            ->assertHasNoErrors();

        $this->assertEquals(1, $message->replies()->count());
        $this->assertEquals($manager->id, $message->replies()->first()->user_id);
    }

    public function test_a_manager_unassigned_to_the_property_cannot_access_a_tenant_initiated_conversation(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'unassigned-manager');
        $unassignedManager = $this->makeManager($landlord, allProperties: false, properties: []);

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Private matter')
            ->set('newBody', 'Only for my landlord.')
            ->call('sendMessage');

        $message = Message::firstOrFail();
        $landlordNotificationId = AppNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $landlord->id)
            ->where('event_type', 'tenant_message')
            ->where('related_id', $message->id)
            ->value('id');

        Livewire::actingAs($unassignedManager)
            ->test(MessagesIndex::class)
            ->set('replyBody', 'Snooping')
            ->call('reply', $landlordNotificationId)
            ->assertForbidden();

        $this->assertEquals(0, $message->replies()->count());
    }

    public function test_the_tenant_cannot_send_a_message_without_an_active_tenancy(): void
    {
        $this->seed();

        $tenant = Tenant::create(['name' => 'No Tenancy Tenant', 'phone' => '0701999888', 'payment_due_day' => 1]);
        $tenantUser = User::create(['name' => $tenant->name, 'email' => 'no-tenancy@example.test', 'password' => bcrypt('password'), 'status' => 'active']);
        $tenantUser->assignRole('tenant');
        $tenant->update(['user_id' => $tenantUser->id]);

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Hello')
            ->set('newBody', 'Anyone there?')
            ->call('sendMessage')
            ->assertHasErrors('newBody');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_the_tenant_can_reply_to_their_own_initiated_conversation(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        [, $tenantUser] = $this->makeTenancyAndPortalUser($landlord, 'self-reply');

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('newSubject', 'Follow-up')
            ->set('newBody', 'Initial message.')
            ->call('sendMessage');

        $message = Message::firstOrFail();
        $ownNotificationId = AppNotification::where('notifiable_type', Tenant::class)
            ->where('notifiable_id', $message->tenant_id)
            ->where('event_type', 'tenant_message')
            ->value('id');

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->call('toggleExpand', $ownNotificationId)
            ->set('replyBody', 'Still waiting on this.')
            ->call('reply', $ownNotificationId)
            ->assertHasNoErrors();

        $this->assertEquals(1, $message->replies()->count());
        $this->assertEquals($tenantUser->id, $message->replies()->first()->user_id);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $landlord->id, 'event_type' => 'message_reply',
        ]);
    }
}
