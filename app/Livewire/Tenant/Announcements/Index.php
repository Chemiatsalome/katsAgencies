<?php

namespace App\Livewire\Tenant\Announcements;

use App\Models\AppNotification;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Index extends Component
{
    use WithPagination;

    /**
     * Both the landlord-initiated broadcast this page was originally built
     * for and a tenant-initiated conversation (F-2) live side by side here
     * as the same event_type set — a tenant's own just-sent message is
     * simply the other direction of the same Message/MessageReply thread.
     */
    private const DISPLAYED_EVENT_TYPES = ['landlord_message', 'tenant_message'];

    public ?int $expandedMessageId = null;

    public string $replyBody = '';

    public string $newSubject = '';

    public string $newBody = '';

    public function toggleExpand(int $announcementId): void
    {
        $this->expandedMessageId = $this->expandedMessageId === $announcementId ? null : $announcementId;
        $this->replyBody = '';
    }

    public function reply(int $announcementId): void
    {
        $tenant = Auth::user()->tenant;
        abort_if(! $tenant, 404);

        $announcement = AppNotification::where('notifiable_type', $tenant->getMorphClass())
            ->where('notifiable_id', $tenant->id)
            ->whereIn('event_type', self::DISPLAYED_EVENT_TYPES)
            ->where('related_type', Message::class)
            ->findOrFail($announcementId);

        $message = $announcement->related;
        abort_unless($message && $message->tenant_id === $tenant->id, 403);

        $this->validate(['replyBody' => ['required', 'string', 'max:2000']]);

        app(MessageService::class)->reply($message, Auth::user(), $this->replyBody);

        $this->replyBody = '';
        session()->flash('status', 'Reply sent.');
    }

    /**
     * F-2: a tenant starting a brand-new conversation, rather than replying
     * to one the landlord opened. The recipient is never taken from client
     * input — it is always the landlord responsible for the tenant's own
     * current tenancy, the same resolution Tenant\Profile uses — so a
     * tenant can never address a message at an arbitrary account. A tenant
     * with no active tenancy (nothing to resolve a responsible landlord
     * from) cannot start a conversation at all.
     */
    public function sendMessage(): void
    {
        $tenant = Auth::user()->tenant;
        abort_if(! $tenant, 404);

        $this->validate([
            'newSubject' => ['required', 'string', 'max:255'],
            'newBody' => ['required', 'string', 'max:2000'],
        ]);

        $tenancy = $tenant->currentTenancy();

        if (! $tenancy) {
            $this->addError('newBody', 'You have no active tenancy to message your landlord about.');

            return;
        }

        $tenancy->load('house.block.property.landlord');
        $property = $tenancy->house->block->property;

        $message = Message::create([
            'sender_id' => $property->landlord_id,
            'tenant_id' => $tenant->id,
            'scope' => 'tenant_initiated',
            'subject' => $this->newSubject,
            'body' => $this->newBody,
        ]);

        $notifications = app(NotificationService::class);

        // Landlord plus every manager assigned to this property — the same
        // audience Messages\Index already lets reply to any thread of
        // theirs, with no narrower messaging permission to enforce (see
        // PermissionSeeder::managerPermissionGroups(): there isn't one).
        $notifications->notifyPropertyStakeholders(
            $property,
            'tenant_message',
            $this->newSubject,
            $this->newBody,
            ['in_app'],
            $message,
        );

        // So the tenant's own new thread appears in their own list right
        // away, exactly like the one it mirrors for the landlord/manager
        // side above — created already-read, since they obviously just
        // wrote it themselves.
        $notifications->notify($tenant, 'tenant_message', $this->newSubject, $this->newBody, ['in_app'], $message);
        $notifications->markRelatedRead($message, $tenant, 'tenant_message');

        $this->reset(['newSubject', 'newBody']);
        session()->flash('status', 'Message sent to your landlord.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;

        $query = AppNotification::where('notifiable_type', $tenant?->getMorphClass())
            ->where('notifiable_id', $tenant?->id ?? 0)
            ->whereIn('event_type', self::DISPLAYED_EVENT_TYPES);

        // Unlike the general Notifications inbox, announcements have no
        // per-item "mark read" control — simply viewing this list is the
        // read action, otherwise read_at could never be set here at all.
        $markedCount = (clone $query)->whereNull('read_at')->update(['read_at' => now()]);

        // Phase 8D: only fire when something actually changed — render()
        // runs on every Livewire update, not just the first page view, so
        // dispatching unconditionally here would fire repeatedly for
        // nothing. Refreshes the sidebar's Announcements badge — see
        // AnnouncementsBadge's own docblock for why a plain re-render here
        // alone wouldn't reach it (the sidebar is @persist'd).
        if ($markedCount > 0) {
            $this->dispatch('tenant-notifications-updated');
        }

        $announcements = $query->with('related.replies.user')->latest()->paginate(15);

        return view('livewire.tenant.announcements.index', [
            'announcements' => $announcements,
        ]);
    }
}
