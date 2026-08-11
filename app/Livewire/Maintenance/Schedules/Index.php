<?php

namespace App\Livewire\Maintenance\Schedules;

use App\Models\MaintenanceSchedule;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function toggleActive(int $scheduleId): void
    {
        $schedule = MaintenanceSchedule::findOrFail($scheduleId);
        $this->authorize('update', $schedule);
        $schedule->update(['is_active' => ! $schedule->is_active]);
    }

    public function delete(int $scheduleId): void
    {
        $schedule = MaintenanceSchedule::findOrFail($scheduleId);
        $this->authorize('delete', $schedule);
        $schedule->delete();
    }

    public function render()
    {
        $landlordId = Auth::id();

        $schedules = MaintenanceSchedule::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with('property')
            ->orderBy('next_due_date')
            ->get();

        return view('livewire.maintenance.schedules.index', [
            'schedules' => $schedules,
        ]);
    }
}
