<?php

namespace App\Livewire\Admin;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[On('notification-sent')]
    public function render()
    {
        // Notifications addressed to this admin specifically (e.g. a new
        // landlord application) plus any delivery that needs operational
        // attention platform-wide (a failed/pending email or SMS send) —
        // both drive the same badge, since either is something an admin
        // should look at.
        $mine = AppNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', Auth::id());

        return view('livewire.admin.notification-bell', [
            'needsAttentionCount' => (clone $mine)->whereNull('read_at')->count()
                + AppNotification::needsAttention()->count(),
            // The dropdown itself stays a platform-wide activity feed (not
            // just this admin's own items) — the badge above is what
            // actually tells them something needs looking at.
            'recent' => AppNotification::latest()->limit(5)->get(),
        ]);
    }
}
