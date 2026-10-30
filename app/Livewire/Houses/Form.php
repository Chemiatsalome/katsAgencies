<?php

namespace App\Livewire\Houses;

use App\Models\Block;
use App\Models\House;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?House $house = null;

    public Block $block;

    public string $house_number = '';

    public string $type = 'bedsitter';

    public ?int $bedrooms = null;

    public string $monthly_rent = '';

    public string $deposit_amount = '';

    public string $electricity_arrangement = 'separate_meter';

    public string $water_arrangement = 'shared';

    public string $status = 'vacant';

    public string $description = '';

    public function mount(?Block $block = null, ?House $house = null): void
    {
        if ($house?->exists) {
            $this->authorize('update', $house);
            $this->house = $house;
            $this->block = $house->block;
            $this->house_number = $house->house_number;
            $this->type = $house->type;
            $this->bedrooms = $house->bedrooms;
            $this->monthly_rent = (string) $house->monthly_rent;
            $this->deposit_amount = (string) $house->deposit_amount;
            $this->electricity_arrangement = $house->electricity_arrangement;
            $this->water_arrangement = $house->water_arrangement;
            $this->status = $house->status;
            $this->description = (string) $house->description;
        } else {
            $this->authorize('create', [House::class, $block]);
            $this->block = $block;
        }
    }

    /**
     * Only the explicitly numbered types (one/two/three/four bedroom) imply
     * a bedroom count — switching to one of those always sets it to match,
     * same as picking it fresh. Anything else (including 'other') is left
     * alone so a manually entered value is never silently overwritten.
     */
    public function updatedType(): void
    {
        $implied = House::bedroomsImpliedByType($this->type);

        if ($implied !== null) {
            $this->bedrooms = $implied;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'house_number' => [
                'required', 'string', 'max:255',
                Rule::unique('houses', 'house_number')
                    ->where('block_id', $this->block->id)
                    ->ignore($this->house?->id),
            ],
            'type' => ['required', Rule::in(House::TYPES)],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['required', 'numeric', 'min:0'],
            'electricity_arrangement' => ['required', 'in:shared,separate_meter,included'],
            'water_arrangement' => ['required', 'in:shared,separate_meter,included'],
            'status' => ['required', 'in:vacant,occupied,reserved,pending_approval,under_maintenance,unavailable'],
            'description' => ['nullable', 'string'],
        ]);

        if ($this->house && ! in_array($this->house->status, ['vacant', 'occupied'], true)) {
            // Reserved/under-maintenance/etc. are set by the tenancy and
            // invitation workflows — an unrelated field edit here shouldn't
            // silently overwrite that system-managed state.
            unset($data['status']);
        }

        if ($this->house) {
            $this->house->update($data);
        } else {
            $this->block->houses()->create($data);
        }

        session()->flash('status', 'House saved.');
        $this->redirectRoute('manage.houses.index', $this->block, navigate: true);
    }

    public function render()
    {
        return view('livewire.houses.form');
    }
}
