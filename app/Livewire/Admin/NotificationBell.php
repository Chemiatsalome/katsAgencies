<?php

namespace App\Livewire\Admin;

use App\Models\AppNotification;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[On('notification-sent')]
    public function render()
    {
        return view('livewire.admin.notification-bell', [
            'needsAttentionCount' => AppNotification::needsAttention()->count(),
            'recent' => AppNotification::latest()->limit(5)->get(),
        ]);
    }
}
