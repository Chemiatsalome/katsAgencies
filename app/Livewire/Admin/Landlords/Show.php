<?php

namespace App\Livewire\Admin\Landlords;

use App\Models\House;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public User $landlord;

    public function mount(User $landlord): void
    {
        $this->landlord = $landlord->loadCount('properties');
    }

    public function render()
    {
        $properties = $this->landlord->properties()->withCount('blocks')->with('houses')->get();
        $houseIds = $properties->pluck('houses')->flatten()->pluck('id');

        $tenancyIds = Tenancy::whereIn('house_id', $houseIds)->active()->pluck('id');
        $expected = (float) RentCharge::whereIn('tenancy_id', $tenancyIds)
            ->forPeriod(now()->year, now()->month)->sum('amount_expected');
        $collected = (float) RentCharge::whereIn('tenancy_id', $tenancyIds)
            ->forPeriod(now()->year, now()->month)->sum('amount_paid');

        $activity = Activity::forSubject($this->landlord)
            ->orWhereHasMorph('subject', [\App\Models\Property::class], fn ($q) => $q->whereIn('id', $properties->pluck('id')))
            ->latest()
            ->limit(15)
            ->get();

        return view('livewire.admin.landlords.show', [
            'properties' => $properties,
            'totalUnits' => $houseIds->count(),
            'occupiedUnits' => House::whereIn('id', $houseIds)->occupied()->count(),
            'vacantUnits' => House::whereIn('id', $houseIds)->vacant()->count(),
            'expectedRent' => $expected,
            'collectedRent' => $collected,
            'activity' => $activity,
        ]);
    }
}
