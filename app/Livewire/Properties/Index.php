<?php

namespace App\Livewire\Properties;

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

    #[Url]
    public int $perPage = 25;

    public function delete(Property $property): void
    {
        $this->authorize('delete', $property);
        $property->delete();
    }

    public function render()
    {
        $user = Auth::user();

        $query = Property::withCount('blocks')
            ->with('houses')
            ->latest();

        if (! $user->hasRole('admin')) {
            $query->forLandlord($user->id);
        }

        return view('livewire.properties.index', [
            'properties' => $query->paginate($this->perPage),
        ]);
    }
}
