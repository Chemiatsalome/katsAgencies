<?php

namespace App\Livewire\Vacancies;

use App\Models\House;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    public function render()
    {
        $houses = House::vacant()
            ->accessibleBy(Auth::user())
            ->with(['block.property', 'tenancies' => fn ($q) => $q->latest('move_out_date')->limit(1)])
            ->get()
            ->map(function (House $house) {
                $lastTenancy = $house->tenancies->first();
                $vacantSince = $lastTenancy?->move_out_date ?? $house->created_at->toDateString();
                $daysVacant = (int) now()->diffInDays($vacantSince, absolute: true);
                $estimatedLostRent = round((float) $house->monthly_rent / 30 * $daysVacant, 2);

                return (object) [
                    'house' => $house,
                    'vacantSince' => $vacantSince,
                    'daysVacant' => $daysVacant,
                    'estimatedLostRent' => $estimatedLostRent,
                ];
            })
            ->sortByDesc('daysVacant')
            ->values();

        return view('livewire.vacancies.index', [
            'rows' => $houses,
            'totalLostRent' => $houses->sum('estimatedLostRent'),
        ]);
    }
}
