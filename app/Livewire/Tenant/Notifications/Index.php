<?php

namespace App\Livewire\Tenant\Notifications;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public function markRead(int $notificationId): void
    {
        $notification = $this->scopedQuery()->findOrFail($notificationId);
        $notification->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $this->scopedQuery()->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function scopedQuery()
    {
        $tenant = Auth::user()->tenant;

        return AppNotification::where('notifiable_type', $tenant?->getMorphClass())
            ->where('notifiable_id', $tenant?->id ?? 0);
    }

    public function render()
    {
        $query = $this->scopedQuery()->latest();

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        return view('livewire.tenant.notifications.index', [
            'notifications' => $query->paginate(20),
            'unreadCount' => $this->scopedQuery()->whereNull('read_at')->count(),
        ]);
    }
}
