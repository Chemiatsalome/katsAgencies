<?php

namespace App\Livewire\ManagerInvitations;

use App\Models\Manager;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Accept extends Component
{
    public Manager $manager;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->manager = Manager::with('landlord', 'properties')
            ->where('token', $token)
            ->firstOrFail();
    }

    public function register(): void
    {
        $this->manager->refresh();

        if (! $this->manager->isUsable()) {
            $this->addError('password', 'This invitation is no longer available.');

            return;
        }

        $this->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        // A defensive re-check, not just a UX nicety: the invited email could
        // have been claimed by a different account (or a race with another
        // tab) between mount() and this submit — without it, User::create()
        // below throws a raw unique-constraint exception instead of a
        // message the manager can actually do something with.
        if (User::where('email', $this->manager->email)->exists()) {
            $this->addError('password', 'An account already exists for this email address. Contact the landlord who invited you.');

            return;
        }

        // Wrapped so a failure partway through (e.g. a misconfigured/missing
        // role) can't leave an orphaned User that's neither role-assigned nor
        // linked back to this Manager — which would also permanently block
        // retrying, since the email would already be taken by the orphan.
        $user = DB::transaction(function () {
            $user = User::create([
                'name' => $this->manager->name,
                'email' => $this->manager->email,
                'password' => Hash::make($this->password),
                'email_verified_at' => now(),
                'status' => 'active',
            ]);
            $user->assignRole('manager');
            $user->syncPermissions($this->manager->permissions ?? []);

            $this->manager->update([
                'user_id' => $user->id,
                'status' => 'active',
                'activated_at' => now(),
            ]);

            return $user;
        });

        // Mirrors tenant_registered on the tenant-invitation side — the
        // landlord who sent this invite previously had no way to know it
        // had actually been accepted short of checking the Managers list.
        app(NotificationService::class)->notify(
            $this->manager->landlord,
            'manager_registered',
            'New Manager Joined Your Team',
            "{$this->manager->name} accepted their invitation and can now access your account.",
            ['in_app'],
            $this->manager,
        );

        Auth::login($user);

        $this->redirectRoute('manager.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.manager-invitations.accept');
    }
}
