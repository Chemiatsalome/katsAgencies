<?php

namespace App\Livewire\Admin\Users;

use App\Mail\LandlordApplicationApprovedPendingVerificationMail;
use App\Models\ManagerReport;
use App\Models\User;
use App\Services\NotificationService;
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

    // --- Phase 4, point C: report a manager to their responsible landlord --------------

    public ?int $pendingReportUserId = null;

    public string $reportReason = '';

    public function startReport(int $userId): void
    {
        $this->pendingReportUserId = $userId;
        $this->reportReason = '';
        $this->resetErrorBag('reportReason');
    }

    public function cancelReport(): void
    {
        $this->reset(['pendingReportUserId', 'reportReason']);
    }

    /**
     * The only manager-concern action an admin has — never a suspend/
     * deactivate action of their own (see startStatusChange()'s guard
     * above). Creates the audit record and notifies the landlord; the
     * landlord's own decision, if any, still goes through the existing
     * Managers\Index::deactivate()/remove() — untouched by this.
     */
    public function submitReport(): void
    {
        $this->validate(['reportReason' => ['required', 'string', 'min:3', 'max:1000']]);

        $managerUser = User::findOrFail($this->pendingReportUserId);
        abort_unless($managerUser->hasRole('manager'), 422);

        $manager = $managerUser->manager;

        if (! $manager) {
            $this->addError('reportReason', 'This account has no active manager delegation to report.');

            return;
        }

        $landlord = User::find($manager->landlord_id);

        $report = ManagerReport::create([
            'manager_id' => $manager->id,
            'landlord_id' => $manager->landlord_id,
            'reported_by' => auth()->id(),
            'reason' => trim($this->reportReason),
        ]);

        // Edge case: the responsible landlord is themselves suspended and
        // can't currently log in to act on anything. The report is still
        // recorded — it's a legitimate audit entry regardless — and still
        // gets an in-app row waiting for them if they're ever reinstated,
        // but an email about something they can't act on yet would just
        // be confusing, so that channel alone is skipped while suspended.
        if ($landlord) {
            app(NotificationService::class)->notify(
                $landlord,
                'manager_reported_by_admin',
                'An Administrator Reported One of Your Managers',
                "An administrator reported {$managerUser->name}: {$report->reason}",
                $landlord->status === 'suspended' ? ['in_app'] : ['in_app', 'email'],
                $report,
            );
        }

        session()->flash('status', "Reported {$managerUser->name} to their landlord.");
        $this->reset(['pendingReportUserId', 'reportReason']);
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
