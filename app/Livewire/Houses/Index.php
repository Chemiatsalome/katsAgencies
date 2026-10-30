<?php

namespace App\Livewire\Houses;

use App\Models\Block;
use App\Models\Tenancy;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public Block $block;

    public function mount(Block $block): void
    {
        $this->authorize('view', $block);
        $this->block = $block;
    }

    public function delete(int $houseId): void
    {
        $house = $this->block->houses()->findOrFail($houseId);
        $this->authorize('delete', $house);

        if ($house->tenancies()->whereIn('status', Tenancy::OCCUPYING_STATUSES)->exists()) {
            session()->flash('status', 'This unit has an active tenancy — end the tenancy before deleting it.');

            return;
        }

        $house->delete();
    }

    public function render()
    {
        return view('livewire.houses.index', [
            'houses' => $this->block->houses()->with('currentTenancy.tenant')->orderBy('house_number')->get(),
        ]);
    }
}
