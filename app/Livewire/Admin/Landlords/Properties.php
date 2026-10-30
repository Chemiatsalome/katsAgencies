<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\Property;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Properties extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url]
    public string $landlordId = 'all';

    #[Url]
    public string $status = 'all';

    public function render()
    {
        $properties = Property::with(['landlord', 'houses'])
            ->withCount('blocks')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->landlordId !== 'all', fn ($q) => $q->where('landlord_id', $this->landlordId))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin.landlords.properties', [
            'properties' => $properties,
            'landlords' => User::approvedLandlords()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
