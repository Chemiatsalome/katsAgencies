<?php

namespace App\Livewire\Tenant;

use App\Services\PendingActionsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/** Phase 8D: see NotificationsBadge's docblock — same mechanism, scoped to announcements. */
class AnnouncementsBadge extends Component
{
    #[On('tenant-notifications-updated')]
    public function render()
    {
        $tenant = Auth::user()->tenant;

        return view('livewire.tenant.announcements-badge', [
            'count' => $tenant ? app(PendingActionsService::class)->tenantUnreadAnnouncementsCount($tenant) : 0,
        ]);
    }
}
