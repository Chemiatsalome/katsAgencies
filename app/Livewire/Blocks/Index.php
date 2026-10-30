<?php

namespace App\Livewire\Blocks;

use App\Models\Property;
use App\Models\Tenancy;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public Property $property;

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);
        $this->property = $property;
    }

    public function delete(int $blockId): void
    {
        $block = $this->property->blocks()->findOrFail($blockId);
        $this->authorize('delete', $block);

        $hasActiveTenancy = Tenancy::whereIn('status', Tenancy::OCCUPYING_STATUSES)
            ->whereHas('house', fn ($q) => $q->where('block_id', $block->id))
            ->exists();

        if ($hasActiveTenancy) {
            session()->flash('status', 'This block has units with active tenancies — end those tenancies before deleting it.');

            return;
        }

        $block->delete();
    }

    public function render()
    {
        return view('livewire.blocks.index', [
            'blocks' => $this->property->blocks()->withCount('houses')->with('houses')->orderBy('name')->get(),
        ]);
    }
}
