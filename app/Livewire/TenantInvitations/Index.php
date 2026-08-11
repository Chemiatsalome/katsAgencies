<?php

namespace App\Livewire\TenantInvitations;

use App\Models\TenantInvitation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public function cancel(int $invitationId): void
    {
        $invitation = TenantInvitation::findOrFail($invitationId);
        $this->authorize('update', $invitation);

        if (! $invitation->isUsable()) {
            return;
        }

        $invitation->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        if ($invitation->house->status === 'reserved') {
            $invitation->house->update(['status' => 'vacant']);
        }

        session()->flash('status', 'Invitation cancelled.');
    }

    public function regenerate(int $invitationId): void
    {
        $invitation = TenantInvitation::findOrFail($invitationId);
        $this->authorize('update', $invitation);

        if (in_array($invitation->status, ['completed'], true)) {
            return;
        }

        $invitation->update([
            'token' => TenantInvitation::generateToken(),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'opened_at' => null,
            'cancelled_at' => null,
        ]);

        if ($invitation->house->status !== 'occupied') {
            $invitation->house->update(['status' => 'reserved']);
        }

        session()->flash('status', 'Invitation regenerated. The previous link no longer works.');
    }

    public function render()
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');

        $query = TenantInvitation::with('house.block.property', 'existingTenant')
            ->latest();

        if (! $isAdmin) {
            $query->whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $user->id));
        }

        return view('livewire.tenant-invitations.index', [
            'invitations' => $query->paginate(20),
        ]);
    }
}
