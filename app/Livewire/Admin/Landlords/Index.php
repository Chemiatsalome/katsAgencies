<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    public function updateStatus(int $userId, string $status): void
    {
        User::role('landlord')->findOrFail($userId)->update(['status' => $status]);
    }

    public function render()
    {
        $query = User::role('landlord')
            ->withCount('properties')
            ->with('properties.blocks.houses')
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest();

        $landlords = $query->paginate(15)->through(function (User $landlord) {
            $houses = $landlord->properties->flatMap(fn ($p) => $p->blocks->flatMap(fn ($b) => $b->houses));

            return (object) [
                'user' => $landlord,
                'propertiesCount' => $landlord->properties_count,
                'unitsCount' => $houses->count(),
                'occupiedCount' => $houses->where('status', 'occupied')->count(),
                'vacantCount' => $houses->where('status', 'vacant')->count(),
            ];
        });

        return view('livewire.admin.landlords.index', ['landlords' => $landlords]);
    }
}
