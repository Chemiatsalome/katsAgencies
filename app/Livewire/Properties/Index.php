<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Models\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public int $perPage = 25;

    public function delete(Property $property): void
    {
        $this->authorize('delete', $property);

        $hasActiveTenancy = Tenancy::whereIn('status', Tenancy::OCCUPYING_STATUSES)
            ->whereHas('house.block', fn ($q) => $q->where('property_id', $property->id))
            ->exists();

        if ($hasActiveTenancy) {
            session()->flash('status', 'This property has units with active tenancies — end those tenancies before deleting it.');

            return;
        }

        $property->delete();
    }

    public function render()
    {
        $user = Auth::user();

        $query = Property::withCount('blocks')
            ->with('houses')
            ->latest()
            ->accessibleBy($user);

        return view('livewire.properties.index', [
            'properties' => $query->paginate($this->perPage),
        ]);
    }
}
