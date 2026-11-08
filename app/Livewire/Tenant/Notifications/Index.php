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

        // Phase 8D: refreshes the sidebar's Notifications badge — see
        // NotificationsBadge's own docblock for why a plain re-render here
        // alone wouldn't reach it (the sidebar is @persist'd).
        $this->dispatch('tenant-notifications-updated');
    }

    public function markAllRead(): void
    {
        $this->scopedQuery()->whereNull('read_at')->update(['read_at' => now()]);

        $this->dispatch('tenant-notifications-updated');
    }

    /**
     * Phase 8D (G-4): landlord_message notifications are announcements —
     * the dedicated Announcements page is their only tenant-facing listing
     * and read-state trigger (viewing that page, not this one). Excluding
     * them here, rather than only in render()'s own query, also means
     * markAllRead() can no longer silently mark an announcement "read" as
     * a side effect of a bulk action on this unrelated page — it stays
     * unread until the tenant actually views it on its own page.
     */
    private function scopedQuery()
    {
        $tenant = Auth::user()->tenant;

        return AppNotification::where('notifiable_type', $tenant?->getMorphClass())
            ->where('notifiable_id', $tenant?->id ?? 0)
            ->where('event_type', '!=', 'landlord_message');
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
