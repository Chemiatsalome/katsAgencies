<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\Property;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Properties extends Component
{
    use WithPagination;

    public string $search = '';

    public function render()
    {
        $properties = Property::with(['landlord', 'houses'])
            ->withCount('blocks')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin.landlords.properties', ['properties' => $properties]);
    }
}
