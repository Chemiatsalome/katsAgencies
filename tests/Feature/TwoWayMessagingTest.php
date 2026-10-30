<?php

namespace Tests\Feature;

use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Tenant\Announcements\Index as AnnouncementsIndex;
use App\Models\AppNotification;
use App\Models\House;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenancyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit finding C5: "Messages" (landlord side) and "Announcements" (tenant
 * side) were the same one-directional broadcast viewed from two sides of
 * the same AppNotification rows — Announcements was purely read-only, with
 * no reply path at all, since there was no thread model behind a bare
 * notification for a reply to belong to. Fixed with Message/MessageReply:
 * one Message row per resolved recipient (a single compose can fan out to
 * many tenants at once), each independently replyable.
 */
class TwoWayMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function createTenancyFor(User $landlord): \App\Models\Tenancy
    {
        $property = $landlord->properties()->firstOrFail();
        $house = House::whereHas('block', fn ($q) => $q->where('property_id', $property->id))->vacant()->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Messaging Test Tenant',
            'phone' => '0700666777',
            'payment_due_day' => 1,
        ]);

        return app(TenancyProvisioningService::class)->createTenancyForExistingTenant($tenant, [
            'house_id' => $house->id,
            'move_in_date' => now()->subMonth()->format('Y-m-d'),
            'monthly_rent' => $house->monthly_rent,
            'deposit_required' => $house->deposit_amount,
            'rent_due_day' => 1,
        ]);
    }

    public function test_sending_a_message_creates_a_replyable_thread_the_tenant_can_see(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenant, $tenant->name, 'messaging-tenant@example.test', 'password');

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')
            ->set('tenant_id', $tenant->id)
            ->set('subject', 'Reminder')
            ->set('body', 'Please remember to pay by the 5th.')
            ->set('channels', ['in_app'])
            ->call('send');

        $message = Message::firstOrFail();
        $this->assertEquals($tenant->id, $message->tenant_id);
        $this->assertEquals($landlord->id, $message->sender_id);

        $component = Livewire::actingAs($tenantUser)->test(AnnouncementsIndex::class);
        $announcements = $component->viewData('announcements');
        $this->assertCount(1, $announcements);
        $this->assertEquals($message->id, $announcements->first()->related->id);
    }

    public function test_a_tenant_can_reply_and_the_landlord_is_notified(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;
        $tenantUser = app(TenancyProvisioningService::class)->createPortalAccount($tenant, $tenant->name, 'reply-tenant@example.test', 'password');

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')->set('tenant_id', $tenant->id)
            ->set('subject', 'Heads up')->set('body', 'Water will be off tomorrow.')
            ->call('send');

        $message = Message::firstOrFail();
        $announcement = AppNotification::where('related_type', Message::class)->where('related_id', $message->id)->firstOrFail();

        Livewire::actingAs($tenantUser)
            ->test(AnnouncementsIndex::class)
            ->call('toggleExpand', $announcement->id)
            ->set('replyBody', 'Thanks for the heads up!')
            ->call('reply', $announcement->id);

        $this->assertEquals(1, $message->replies()->count());
        $this->assertEquals($tenantUser->id, $message->replies()->first()->user_id);

        $this->assertTrue(
            AppNotification::where('notifiable_type', $landlord->getMorphClass())
                ->where('notifiable_id', $landlord->id)
                ->where('event_type', 'message_reply')
                ->exists()
        );
    }

    public function test_a_landlord_can_reply_and_the_tenant_is_notified(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')->set('tenant_id', $tenant->id)
            ->set('subject', 'Notice')->set('body', 'Inspection next week.')
            ->call('send');

        $message = Message::firstOrFail();
        $announcement = AppNotification::where('related_type', Message::class)->where('related_id', $message->id)->firstOrFail();

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->call('toggleExpand', $announcement->id)
            ->set('replyBody', 'Confirmed, see you then.')
            ->call('reply', $announcement->id);

        $this->assertEquals(1, $message->replies()->count());
        $this->assertTrue(
            AppNotification::where('notifiable_type', $tenant->getMorphClass())
                ->where('notifiable_id', $tenant->id)
                ->where('event_type', 'message_reply')
                ->exists()
        );
    }

    public function test_a_tenant_cannot_reply_to_another_tenants_message(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        $tenancy = $this->createTenancyFor($landlord);
        $tenant = $tenancy->tenant;

        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'phone' => '0700888999', 'payment_due_day' => 1]);
        $otherTenantUser = app(TenancyProvisioningService::class)->createPortalAccount($otherTenant, $otherTenant->name, 'other-tenant@example.test', 'password');

        Livewire::actingAs($landlord)
            ->test(MessagesIndex::class)
            ->set('scope', 'tenant')->set('tenant_id', $tenant->id)
            ->set('subject', 'Private')->set('body', 'Just for you.')
            ->call('send');

        $message = Message::firstOrFail();
        $announcement = AppNotification::where('related_type', Message::class)->where('related_id', $message->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($otherTenantUser)
            ->test(AnnouncementsIndex::class)
            ->set('replyBody', 'Snooping')
            ->call('reply', $announcement->id);
    }
}
