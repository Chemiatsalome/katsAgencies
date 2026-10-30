<?php

namespace App\Livewire\Occupancy;

use App\Models\House;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Index extends Component
{
    private const STATUS_META = [
        'occupied' => ['label' => 'Occupied', 'color' => '#2E8B57'],
        'vacant' => ['label' => 'Vacant', 'color' => '#94A3B8'],
        'reserved' => ['label' => 'Reserved', 'color' => '#D99000'],
        'pending_approval' => ['label' => 'Pending Approval', 'color' => '#D99000'],
        'under_maintenance' => ['label' => 'Under Maintenance', 'color' => '#D99000'],
        'unavailable' => ['label' => 'Unavailable', 'color' => '#C94A4A'],
    ];

    public function render()
    {
        $user = Auth::user();
        $houseQuery = House::accessibleBy($user);

        $statusCounts = (clone $houseQuery)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $totalUnits = $statusCounts->sum();
        $occupied = $statusCounts->get('occupied', 0);

        $chartRows = collect(self::STATUS_META)
            ->map(fn ($meta, $status) => ['label' => $meta['label'], 'color' => $meta['color'], 'count' => $statusCounts->get($status, 0)])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();

        $byProperty = Property::accessibleBy($user)->with('houses')->orderBy('name')->get()->map(function (Property $property) {
            $total = $property->houses->count();
            $occupied = $property->houses->where('status', 'occupied')->count();

            return (object) [
                'property' => $property,
                'total' => $total,
                'occupied' => $occupied,
                'rate' => $total > 0 ? round($occupied / $total * 100, 1) : 0,
            ];
        });

        return view('livewire.occupancy.index', [
            'statusCounts' => $statusCounts,
            'totalUnits' => $totalUnits,
            'occupancyRate' => $totalUnits > 0 ? round($occupied / $totalUnits * 100, 1) : 0,
            'chartRows' => $chartRows,
            'byProperty' => $byProperty,
        ]);
    }
}
