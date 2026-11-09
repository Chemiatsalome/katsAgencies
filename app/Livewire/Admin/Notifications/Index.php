<?php

namespace App\Livewire\Admin\Notifications;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $channel = 'all';

    public string $status = 'all';

    /**
     * G-1B: a dedicated toggle rather than overloading the existing status
     * filter — `submission_unknown` lives in delivery_status, not status,
     * and this filter needs to force the channel too (unresolved email
     * only makes sense scoped to channel='email').
     */
    public bool $onlyUnresolvedEmail = false;

    public function markRead(int $notificationId): void
    {
        $this->myNotifications()->where('id', $notificationId)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $this->myNotifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function myNotifications()
    {
        return AppNotification::where('notifiable_type', User::class)->where('notifiable_id', Auth::id());
    }

    public function render()
    {
        $query = AppNotification::with('notifiable')
            ->when($this->onlyUnresolvedEmail, fn ($q) => $q->unresolvedEmail())
            ->when(! $this->onlyUnresolvedEmail && $this->channel !== 'all', fn ($q) => $q->where('channel', $this->channel))
            ->when(! $this->onlyUnresolvedEmail && $this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        return view('livewire.admin.notifications.index', [
            'notifications' => $query->paginate(25),
            'myUnreadCount' => $this->myNotifications()->whereNull('read_at')->count(),
            'unresolvedEmailCount' => AppNotification::unresolvedEmail()->count(),
        ]);
    }
}
