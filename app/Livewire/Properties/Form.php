<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class Form extends Component
{
    use WithFileUploads;

    public ?Property $property = null;

    public ?int $landlord_id = null;

    public string $name = '';

    public string $type = 'other';

    public string $address = '';

    /** Only ever set when the landlord explicitly confirmed a location-autocomplete suggestion — never from free typing. */
    public ?float $latitude = null;

    public ?float $longitude = null;

    public $photo = null;

    public string $description = '';

    public string $status = 'active';

    public string $purchase_price = '';

    public string $initial_renovation_cost = '';

    public string $other_acquisition_costs = '';

    public string $current_estimated_value = '';

    public function mount(?Property $property = null): void
    {
        $user = Auth::user();

        if ($property?->exists) {
            $this->authorize('update', $property);
            $this->property = $property;
            $this->landlord_id = $property->landlord_id;
            $this->name = $property->name;
            $this->type = $property->type;
            $this->address = (string) $property->address;
            $this->latitude = $property->latitude !== null ? (float) $property->latitude : null;
            $this->longitude = $property->longitude !== null ? (float) $property->longitude : null;
            $this->description = (string) $property->description;
            $this->status = $property->status;
            $this->purchase_price = (string) $property->purchase_price;
            $this->initial_renovation_cost = (string) $property->initial_renovation_cost;
            $this->other_acquisition_costs = (string) $property->other_acquisition_costs;
            $this->current_estimated_value = (string) $property->current_estimated_value;
        } else {
            $this->authorize('create', Property::class);
            $this->landlord_id = $user->hasRole('admin') ? null : $user->landlordId();
        }
    }

    public function save(): void
    {
        // Never trust the client-held landlord_id for anyone but admin — a
        // manager (granted properties.create) or landlord could otherwise
        // submit an arbitrary landlord_id and create a property they don't
        // own. Admin alone picks a landlord via the visible dropdown.
        if (! Auth::user()->hasRole('admin')) {
            $this->landlord_id = Auth::user()->landlordId();
        }

        $data = $this->validate([
            'landlord_id' => ['required', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Property::TYPES)],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'initial_renovation_cost' => ['nullable', 'numeric', 'min:0'],
            'other_acquisition_costs' => ['nullable', 'numeric', 'min:0'],
            'current_estimated_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        // 'photo' isn't a column — it only ever produces a new photo_path
        // when the landlord actually picked a replacement. Leaving
        // photo_path out of $data entirely when no file was chosen is what
        // preserves whatever the property already had.
        unset($data['photo']);
        if ($this->photo) {
            $data['photo_path'] = $this->photo->store('property-photos', 'public');
        }

        foreach (['purchase_price', 'initial_renovation_cost', 'other_acquisition_costs', 'current_estimated_value'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

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
