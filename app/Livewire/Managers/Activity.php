<?php

namespace App\Livewire\Managers;

use App\Models\Manager;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity as ActivityLog;

#[Layout('layouts.admin')]
class Activity extends Component
{
    use WithPagination;

    public Manager $manager;

    public function mount(Manager $manager): void
    {
        $this->authorize('view', $manager);
        $this->manager = $manager;
    }

    public function render()
    {
        $entries = $this->manager->user_id
            ? ActivityLog::where('causer_type', User::class)
                ->where('causer_id', $this->manager->user_id)
                ->latest()
                ->paginate(25)
            : ActivityLog::whereRaw('1 = 0')->paginate(25);

        return view('livewire.managers.activity', ['entries' => $entries]);
    }
}
