<?php

namespace App\Livewire\Units;

use App\Models\Block;
use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $status = 'all';

    public string $search = '';

    #[Url]
    public string $propertyId = 'all';

    #[Url]
    public string $blockId = 'all';

    #[Url]
    public int $perPage = 25;

    public function updatedPropertyId(): void
    {
        $this->blockId = 'all';
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->orderBy('name')->get(['id', 'name']);

        $blocksQuery = Block::accessibleBy($user)->when(
            $this->propertyId !== 'all',
            fn ($q) => $q->where('property_id', $this->propertyId)
        );
        $blocks = $blocksQuery->orderBy('name')->get(['id', 'name', 'property_id']);

        $query = House::with(['block.property', 'currentTenancy.tenant'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where('house_number', 'like', "%{$this->search}%"))
            ->when($this->propertyId !== 'all', fn ($q) => $q->whereHas('block', fn ($q2) => $q2->where('property_id', $this->propertyId)))
            ->when($this->blockId !== 'all', fn ($q) => $q->where('block_id', $this->blockId))
            ->orderBy('house_number')
            ->accessibleBy($user);

        return view('livewire.units.index', [
            'houses' => $query->paginate($this->perPage),
            'properties' => $properties,
            'blocks' => $blocks,
        ]);
    }
}
