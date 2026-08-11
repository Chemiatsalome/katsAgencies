<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Activity extends Component
{
    use WithPagination;

    public function render()
    {
        $entries = ActivityLog::whereHasMorph('causer', [User::class], fn ($q) => $q->role('landlord'))
            ->with('causer')
            ->latest()
            ->paginate(25);

        return view('livewire.admin.landlords.activity', ['entries' => $entries]);
    }
}
