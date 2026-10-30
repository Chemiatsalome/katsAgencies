<?php

namespace App\Livewire;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[On('notification-sent')]
    public function render()
    {
        $query = AppNotification::forLandlordContext(Auth::user());

        return view('livewire.notification-bell', [
            // Was needsAttention() alone (status pending/failed) — an in-app
            // notification is marked 'sent' synchronously right after it's
            // created (see NotificationService::deliver()), so it never sits
            // in 'pending' long enough to count, and essentially never
            // 'failed' either. That left this badge always reading ~0
            // regardless of how many notifications actually existed. The
            // badge should answer "do I have something unread", which
            // read_at actually tracks — mirrors Tenant\NotificationBell.
            'needsAttentionCount' => (clone $query)->whereNull('read_at')->count(),
            'recent' => (clone $query)->latest()->limit(5)->get(),
        ]);
    }
}
