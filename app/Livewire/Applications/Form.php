<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?int $desired_property_id = null;

    public ?int $desired_house_id = null;

    public string $applicant_name = '';

    public string $applicant_phone = '';

    public string $applicant_email = '';

    public string $application_date;

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('create', Application::class);
        $this->application_date = now()->format('Y-m-d');
    }

    public function save(): void
    {
        $data = $this->validate([
            'desired_property_id' => ['required', 'exists:properties,id'],
            'desired_house_id' => ['nullable', 'exists:houses,id'],
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_phone' => ['required', 'string', 'max:32'],
            'applicant_email' => ['nullable', 'email'],
            'application_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $property = Property::findOrFail($this->desired_property_id);
        $this->authorize('update', $property);

        $data['status'] = 'submitted';

        $application = Application::create($data);

        session()->flash('status', 'Application logged.');
        $this->redirectRoute('manage.applications.show', $application, navigate: true);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.applications.form', [
            'properties' => Property::accessibleBy($user)->orderBy('name')->get(),
            'houses' => $this->desired_property_id
                ? House::whereHas('block', fn ($q) => $q->where('property_id', $this->desired_property_id))->vacant()->get()
                : collect(),
        ]);
    }
}
