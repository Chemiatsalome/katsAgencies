<?php

namespace App\Livewire\Admin\Users;

use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = 'all';

    public ?int $resetPasswordUserId = null;

    public ?string $generatedPassword = null;

    // --- Phase 3: reason capture for suspending/deactivating a user --------------------

    public ?int $pendingStatusUserId = null;

    public ?string $pendingStatusValue = null;

    public string $reason = '';

    /** Reactivating needs no reason — only starts the reveal for a status that actually restricts access. */
    public function startStatusChange(int $userId, string $status): void
    {
        // Product rule: an admin cannot independently suspend or deactivate
        // a manager — only the responsible landlord can, via Managers\Index.
        // Blocked here too (not just by hiding the control), since this is
        // itself a directly callable Livewire action.
        if (User::findOrFail($userId)->hasRole('manager')) {
            session()->flash('status', 'Managers cannot be suspended or deactivated directly by an admin. Report a concern to the manager\'s landlord instead — they decide whether to act.');

            return;
        }

        if ($status === 'active') {
            $this->updateStatus($userId, $status);

            return;
        }

        $this->pendingStatusUserId = $userId;
        $this->pendingStatusValue = $status;
        $this->reason = '';
        $this->resetErrorBag('reason');
    }

    public function cancelStatusChange(): void
    {
        $this->reset(['pendingStatusUserId', 'pendingStatusValue', 'reason']);
    }

    public function confirmStatusChange(): void
    {
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->updateStatus((int) $this->pendingStatusUserId, (string) $this->pendingStatusValue, $this->reason);

        $this->reset(['pendingStatusUserId', 'pendingStatusValue', 'reason']);
    }

    public function updateRole(int $userId, string $role): void
    {
        abort_unless(in_array($role, ['admin', 'landlord', 'tenant'], true), 422);

        if ($userId === auth()->id() && $role !== 'admin') {
            session()->flash('status', "You can't remove your own admin role.");

            return;
        }

        $user = User::findOrFail($userId);
        $user->syncRoles([$role]);

        session()->flash('status', "{$user->name}'s role updated to ".ucfirst($role).'.');
    }

    public function updateStatus(int $userId, string $status, string $reason = ''): void
    {
        abort_unless(in_array($status, ['active', 'inactive', 'suspended'], true), 422);

        if ($userId === auth()->id() && $status !== 'active') {
            session()->flash('status', "You can't change your own account status.");

            return;
        }

        // Enforced here, not only by confirmStatusChange()'s own validate()
        // call — this method is itself a directly callable Livewire action,
        // so the reason requirement has to hold regardless of which path
        // reaches it.
        if ($status !== 'active' && trim($reason) === '') {
            $this->addError('reason', 'A reason is required to deactivate or suspend an account.');

            return;
        }

        $user = User::findOrFail($userId);

        // Enforced here too, not only by startStatusChange() — a manager's
        // status is never admin-controlled, regardless of which path
        // reaches this method.
        if ($user->hasRole('manager')) {
            session()->flash('status', 'Managers cannot be suspended or deactivated directly by an admin. Report a concern to the manager\'s landlord instead — they decide whether to act.');

            return;
        }

        // This general-purpose control reaches every account type,
        // landlords included — it must not become a side door around the
        // same email-verification gate Applications::approve() and
        // Landlords\Index::updateStatus() enforce (see
        // User::isAwaitingEmailVerification()).
        if ($status === 'active' && $user->hasRole('landlord') && ! $user->hasVerifiedEmail()) {
            $user->update(['approved_at' => $user->approved_at ?? now()]);

            Mail::to($user->email)->send(
                new LandlordApplicationApprovedPendingVerificationMail($user->name, $user->verificationUrl())
            );

            session()->flash('status', "{$user->name}'s email isn't verified yet, so they can't be activated yet — we've emailed them a verification link instead. They'll activate automatically once they confirm it.");

            return;
        }

        if ($status !== 'active') {
            $user->pendingStatusChangeReason = trim($reason);
        }

        $user->update(['status' => $status]);

        session()->flash('status', "{$user->name}'s status updated to ".ucfirst($status).'.');
    }

    public function resetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);
        $password = Str::password(12);
        $user->update(['password' => Hash::make($password)]);

        $this->resetPasswordUserId = $userId;
        $this->generatedPassword = $password;
    }

    public function render()
    {
        $query = User::with('roles')
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->roleFilter !== 'all', fn ($q) => $q->role($this->roleFilter))
            ->latest();

        return view('livewire.admin.users.index', [
            'users' => $query->paginate(20),
        ]);
    }
}
