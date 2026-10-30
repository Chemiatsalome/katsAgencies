<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

    public function updateStatus(int $userId, string $status): void
    {
        abort_unless(in_array($status, ['active', 'inactive', 'suspended'], true), 422);

        if ($userId === auth()->id() && $status !== 'active') {
            session()->flash('status', "You can't change your own account status.");

            return;
        }

        $user = User::findOrFail($userId);
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
