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
        $user = User::findOrFail($userId);
        $user->syncRoles([$role]);
    }

    public function updateStatus(int $userId, string $status): void
    {
        $user = User::findOrFail($userId);
        $user->update(['status' => $status]);
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
