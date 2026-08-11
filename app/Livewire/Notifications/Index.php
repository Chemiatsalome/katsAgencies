<?php

namespace App\Livewire\Notifications;

use App\Models\AppNotification;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    #[Url]
    public string $status = 'all';

    public function render()
    {
        $landlordId = Auth::id();

        $tenantIds = Tenant::whereHas('tenancies.house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))->pluck('id');

        $query = AppNotification::where('notifiable_type', (new Tenant)->getMorphClass())
            ->whereIn('notifiable_id', $tenantIds)
            ->with('notifiable');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        return view('livewire.notifications.index', [
            'notifications' => $query->latest()->limit(100)->get(),
        ]);
    }
}
