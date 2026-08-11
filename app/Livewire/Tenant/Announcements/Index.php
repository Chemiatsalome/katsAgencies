<?php

namespace App\Livewire\Tenant\Announcements;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant')]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        $tenant = Auth::user()->tenant;

        $announcements = AppNotification::where('notifiable_type', $tenant?->getMorphClass())
            ->where('notifiable_id', $tenant?->id ?? 0)
            ->where('event_type', 'landlord_message')
            ->latest()
            ->paginate(15);

        return view('livewire.tenant.announcements.index', [
            'announcements' => $announcements,
        ]);
    }
}
