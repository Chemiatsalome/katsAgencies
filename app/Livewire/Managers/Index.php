<?php

namespace App\Livewire\Managers;

use App\Models\Manager;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Manager::class);
    }

    public function cancelInvitation(Manager $manager): void
    {
        $this->authorize('delete', $manager);

        if ($manager->status !== 'invited') {
            return;
        }

        $manager->delete();

        session()->flash('status', 'Invitation cancelled.');
    }

    public function regenerate(Manager $manager): void
    {
        $this->authorize('update', $manager);

        if ($manager->status !== 'invited') {
            return;
        }

        $manager->update([
            'token' => Manager::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        session()->flash('status', 'Invitation regenerated. The previous link no longer works.');
    }

    public function deactivate(Manager $manager): void
    {
        $this->authorize('update', $manager);

        if ($manager->status !== 'active') {
            return;
        }

        $manager->update(['status' => 'inactive']);
        $manager->user?->update(['status' => 'inactive']);

        // Previously silent — the manager only found out via a failed
        // login, with no reason given at all.
        if ($manager->user) {
            app(NotificationService::class)->notify(
                $manager->user,
                'manager_deactivated',
                'Your Access Has Been Deactivated',
                'Your landlord has deactivated your access to Kats Agencies.',
                ['in_app', 'email'],
                $manager,
            );
        }

        session()->flash('status', 'Manager access deactivated.');
    }

    public function reactivate(Manager $manager): void
    {
        $this->authorize('update', $manager);

        if ($manager->status !== 'inactive') {
            return;
        }

        $manager->update(['status' => 'active']);
        $manager->user?->update(['status' => 'active']);

        if ($manager->user) {
            app(NotificationService::class)->notify(
                $manager->user,
                'manager_reactivated',
                'Your Access Has Been Reactivated',
                'Your landlord has reactivated your access to Kats Agencies.',
                ['in_app', 'email'],
                $manager,
            );
        }

        session()->flash('status', 'Manager access reactivated.');
    }

    // Only offered once a manager is already deactivated — a deliberate
    // two-step action rather than a one-click delete from an active state.
    public function remove(Manager $manager): void
    {
        $this->authorize('delete', $manager);

        if ($manager->status !== 'inactive') {
            return;
        }

        // The User row is deliberately kept, not hard-deleted — removing it
        // used to silently degrade every past activity_log entry (its
        // causer_id/type is a nullable polymorphic pair with no FK) and
        // null out maintenance_tasks.created_by / complaints.assigned_to
        // (both nullOnDelete) for everything this manager ever touched.
        // The account is already permanently inactive and unable to log
        // in (same enforcement as deactivate()) — only the delegation
        // record itself goes.
        $manager->delete();

        session()->flash('status', 'Manager removed.');
    }

    public function render()
    {
        $landlordId = Auth::id();

        $managers = Manager::where('landlord_id', $landlordId)
            ->with('properties:id,name')
            ->latest()
            ->get();

        return view('livewire.managers.index', [
            'managers' => $managers,
        ]);
    }
}
