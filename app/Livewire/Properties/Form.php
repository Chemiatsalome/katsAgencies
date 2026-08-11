<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?Property $property = null;

    public ?int $landlord_id = null;

    public string $name = '';

    public string $address = '';

    public string $description = '';

    public string $status = 'active';

    public function mount(?Property $property = null): void
    {
        $user = Auth::user();

        if ($property?->exists) {
            $this->authorize('update', $property);
            $this->property = $property;
            $this->landlord_id = $property->landlord_id;
            $this->name = $property->name;
            $this->address = (string) $property->address;
            $this->description = (string) $property->description;
            $this->status = $property->status;
        } else {
            $this->authorize('create', Property::class);
            $this->landlord_id = $user->hasRole('landlord') ? $user->id : null;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'landlord_id' => ['required', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($this->property) {
            $this->property->update($data);
        } else {
            Property::create($data);
        }

        session()->flash('status', 'Property saved.');
        $this->redirectRoute('manage.properties.index', navigate: true);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.properties.form', [
            'landlords' => $user->hasRole('admin') ? User::role('landlord')->orderBy('name')->get() : collect(),
        ]);
    }
}
