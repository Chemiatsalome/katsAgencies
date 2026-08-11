<?php

namespace App\Livewire\Blocks;

use App\Models\Block;
use App\Models\Property;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Form extends Component
{
    public ?Block $block = null;

    public Property $property;

    public string $name = '';

    public string $description = '';

    public function mount(?Property $property = null, ?Block $block = null): void
    {
        if ($block?->exists) {
            $this->authorize('update', $block);
            $this->block = $block;
            $this->property = $block->property;
            $this->name = $block->name;
            $this->description = (string) $block->description;
        } else {
            $this->authorize('create', Block::class);
            $this->property = $property;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('blocks', 'name')
                    ->where('property_id', $this->property->id)
                    ->ignore($this->block?->id),
            ],
            'description' => ['nullable', 'string'],
        ]);

        if ($this->block) {
            $this->block->update($data);
        } else {
            $this->property->blocks()->create($data);
        }

        session()->flash('status', 'Block saved.');
        $this->redirectRoute('manage.blocks.index', $this->property, navigate: true);
    }

    public function render()
    {
        return view('livewire.blocks.form');
    }
}
