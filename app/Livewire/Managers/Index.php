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
    // --- Phase 3: reason capture for deactivating/removing a manager -------------------

    public ?int $pendingDeactivateManagerId = null;

    public string $deactivateReason = '';

    public ?int $pendingRemoveManagerId = null;

    public string $removeReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Manager::class);
    }

    public function startDeactivate(int $managerId): void
    {
        $this->pendingDeactivateManagerId = $managerId;
        $this->deactivateReason = '';
        $this->resetErrorBag('deactivateReason');
    }

    public function cancelDeactivate(): void
    {
        $this->reset(['pendingDeactivateManagerId', 'deactivateReason']);
    }

    public function confirmDeactivate(): void
    {
        $this->validate(['deactivateReason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->deactivate(Manager::findOrFail($this->pendingDeactivateManagerId), $this->deactivateReason);

        $this->reset(['pendingDeactivateManagerId', 'deactivateReason']);
    }

    public function startRemove(int $managerId): void
    {
        $this->pendingRemoveManagerId = $managerId;
        $this->removeReason = '';
        $this->resetErrorBag('removeReason');
    }

    public function cancelRemove(): void
    {
        $this->reset(['pendingRemoveManagerId', 'removeReason']);
    }

    public function confirmRemove(): void
    {
        $this->validate(['removeReason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->remove(Manager::findOrFail($this->pendingRemoveManagerId), $this->removeReason);

        $this->reset(['pendingRemoveManagerId', 'removeReason']);
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

    public function deactivate(Manager $manager, string $reason = ''): void
    {
        $this->authorize('update', $manager);

        if ($manager->status !== 'active') {
            return;
        }

        // Enforced here too, not only by confirmDeactivate()'s own
        // validate() — deactivate() is itself a directly callable Livewire
        // action, so the reason requirement has to hold regardless of
        // which path reaches it.
        if (trim($reason) === '') {
            $this->addError('deactivateReason', 'A reason is required to deactivate a manager.');

            return;
        }

        $manager->pendingActionReason = trim($reason);
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
    public function remove(Manager $manager, string $reason = ''): void
    {
        $this->authorize('delete', $manager);

        if ($manager->status !== 'inactive') {
            return;
        }

        if (trim($reason) === '') {
            $this->addError('removeReason', 'A reason is required to remove a manager.');

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
        $manager->pendingActionReason = trim($reason);
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
