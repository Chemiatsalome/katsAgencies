<?php

namespace App\Livewire\Maintenance\Schedules;

use App\Models\MaintenanceSchedule;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?MaintenanceSchedule $schedule = null;

    public ?int $property_id = null;

    public string $title = '';

    public string $category = 'general';

    public int $frequency_months = 6;

    public string $next_due_date;

    public string $notes = '';

    public bool $is_active = true;

    public function mount(?MaintenanceSchedule $schedule = null): void
    {
        $this->next_due_date = now()->addMonths(6)->format('Y-m-d');

        if ($schedule && $schedule->exists) {
            $this->authorize('update', $schedule);
            $this->schedule = $schedule;
            $this->property_id = $schedule->property_id;
            $this->title = $schedule->title;
            $this->category = $schedule->category;
            $this->frequency_months = $schedule->frequency_months;
            $this->next_due_date = $schedule->next_due_date->format('Y-m-d');
            $this->notes = $schedule->notes ?? '';
            $this->is_active = $schedule->is_active;
        } else {
            $this->authorize('create', MaintenanceSchedule::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'frequency_months' => ['required', 'integer', 'min:1', 'max:60'],
            'next_due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $property = Property::findOrFail($data['property_id']);
        $this->authorize('update', $property);

        if ($this->schedule) {
            $this->authorize('update', $this->schedule);
            $this->schedule->update($data);
        } else {
            MaintenanceSchedule::create($data);
        }

        session()->flash('status', 'Maintenance schedule saved.');
        $this->redirectRoute('manage.maintenance.schedules.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.maintenance.schedules.form', [
            'properties' => Property::forLandlord(Auth::id())->orderBy('name')->get(),
        ]);
    }
}
