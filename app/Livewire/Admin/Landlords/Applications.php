<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Applications extends Component
{
    use WithPagination;

    public function approve(int $userId): void
    {
        $user = User::role('landlord')->findOrFail($userId);
        $user->update(['status' => 'active']);
    }

    public function reject(int $userId): void
    {
        $user = User::role('landlord')->where('status', 'inactive')->findOrFail($userId);
        $user->delete();
    }

    public function render()
    {
        $pending = User::role('landlord')
            ->where('status', 'inactive')
            ->latest()
            ->paginate(15);

        return view('livewire.admin.landlords.applications', ['pending' => $pending]);
    }
}
