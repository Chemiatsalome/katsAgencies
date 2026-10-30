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
            ->when($this->channel !== 'all', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        return view('livewire.admin.notifications.index', [
            'notifications' => $query->paginate(25),
            'myUnreadCount' => $this->myNotifications()->whereNull('read_at')->count(),
        ]);
    }
}
