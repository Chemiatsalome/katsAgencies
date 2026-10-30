<?php

namespace App\Livewire\Tenant\Announcements;

use App\Models\AppNotification;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Index extends Component
{
    use WithPagination;

    public ?int $expandedMessageId = null;

    public string $replyBody = '';

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
            ->where('event_type', 'landlord_message')
            ->where('related_type', Message::class)
            ->findOrFail($announcementId);

        $message = $announcement->related;
        abort_unless($message && $message->tenant_id === $tenant->id, 403);

        $this->validate(['replyBody' => ['required', 'string', 'max:2000']]);

        app(MessageService::class)->reply($message, Auth::user(), $this->replyBody);

        $this->replyBody = '';
        session()->flash('status', 'Reply sent.');
    }

    public function render()
    {
        $tenant = Auth::user()->tenant;

        $query = AppNotification::where('notifiable_type', $tenant?->getMorphClass())
            ->where('notifiable_id', $tenant?->id ?? 0)
            ->where('event_type', 'landlord_message');

        // Unlike the general Notifications inbox, announcements have no
        // per-item "mark read" control — simply viewing this list is the
        // read action, otherwise read_at could never be set here at all.
        (clone $query)->whereNull('read_at')->update(['read_at' => now()]);

        $announcements = $query->with('related.replies.user')->latest()->paginate(15);

        return view('livewire.tenant.announcements.index', [
            'announcements' => $announcements,
        ]);
    }
}
