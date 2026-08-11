<?php

namespace App\Livewire\Tenant;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[On('notification-sent')]
    public function render()
    {
        $tenant = Auth::user()->tenant;

        $query = $tenant
            ? AppNotification::where('notifiable_type', $tenant->getMorphClass())->where('notifiable_id', $tenant->id)
            : AppNotification::query()->whereRaw('1 = 0');

        return view('livewire.tenant.notification-bell', [
            'unreadCount' => (clone $query)->whereNull('read_at')->count(),
            'recent' => (clone $query)->latest()->limit(5)->get(),
        ]);
    }
}
