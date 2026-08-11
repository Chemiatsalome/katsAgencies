<?php

namespace App\Livewire\Houses;

use App\Models\House;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public House $house;

    public function mount(House $house): void
    {
        $this->authorize('view', $house);
        $this->house = $house;
    }

    public function render()
    {
        return view('livewire.houses.show', [
            'tenancies' => $this->house->tenancies()->with('tenant')->latest('move_in_date')->get(),
            'maintenanceTasks' => $this->house->maintenanceTasks()->with('vendor')->latest()->limit(20)->get(),
        ]);
    }
}
