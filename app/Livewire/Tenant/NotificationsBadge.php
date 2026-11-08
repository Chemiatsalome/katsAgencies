<?php

namespace App\Livewire\Tenant;

use App\Services\PendingActionsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Phase 8D: the tenant sidebar is wrapped in @persist, so a plain blade
 * count baked into that partial only reflects reality at the moment the
 * sidebar was first mounted — it never updates again without a full page
 * reload. A nested Livewire component keeps its own independent wire:id
 * and lifecycle regardless of an ancestor's @persist, so it can still be
 * targeted and re-rendered by an AJAX round-trip triggered from anywhere
 * else on the page. Tenant\Notifications\Index dispatches the event this
 * listens for whenever it actually changes read state.
 */
class NotificationsBadge extends Component
{
    #[On('tenant-notifications-updated')]
    public function render()
    {
        $tenant = Auth::user()->tenant;

        return view('livewire.tenant.notifications-badge', [
            'count' => $tenant ? app(PendingActionsService::class)->tenantUnreadNotificationsCount($tenant) : 0,
        ]);
    }
}
