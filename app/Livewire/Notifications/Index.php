<?php

namespace App\Livewire\Notifications;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    #[Url]
    public string $status = 'all';

    public function markRead(int $notificationId): void
    {
        $notification = AppNotification::forLandlordContext(Auth::user())->findOrFail($notificationId);
        $notification->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        AppNotification::forLandlordContext(Auth::user())->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render()
    {
        $user = Auth::user();

        $query = AppNotification::forLandlordContext($user)->with('notifiable');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        return view('livewire.notifications.index', [
            'notifications' => $query->latest()->limit(100)->get(),
            'unreadCount' => AppNotification::forLandlordContext($user)->whereNull('read_at')->count(),
        ]);
    }
}
