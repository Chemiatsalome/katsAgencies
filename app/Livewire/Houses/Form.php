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
            $this->authorize('create', House::class);
            $this->block = $block;
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
            'type' => ['required', 'in:bedsitter,one_bedroom,two_bedroom,main_house,sq,other'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['required', 'numeric', 'min:0'],
            'electricity_arrangement' => ['required', 'in:shared,separate_meter,included'],
            'water_arrangement' => ['required', 'in:shared,separate_meter,included'],
            'status' => ['required', 'in:vacant,occupied'],
            'description' => ['nullable', 'string'],
        ]);

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
