<?php

namespace App\Livewire\Admin\Platform;

use App\Models\House;
use App\Models\Property;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Occupancy extends Component
{
    private const STATUS_META = [
        'occupied' => ['label' => 'Occupied', 'color' => '#2E8B57'],
        'vacant' => ['label' => 'Vacant', 'color' => '#94A3B8'],
        'reserved' => ['label' => 'Reserved', 'color' => '#D99000'],
        'pending_approval' => ['label' => 'Pending Approval', 'color' => '#D99000'],
        'under_maintenance' => ['label' => 'Under Maintenance', 'color' => '#D99000'],
        'unavailable' => ['label' => 'Unavailable', 'color' => '#C94A4A'],
    ];

    public ?int $landlordId = null;

    public function render()
    {
        $houseQuery = House::query()
            ->when($this->landlordId, fn ($q) => $q->whereHas(
                'block.property',
                fn ($q2) => $q2->where('landlord_id', $this->landlordId)
            ));

        $statusCounts = (clone $houseQuery)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $totalUnits = $statusCounts->sum();
        $occupied = $statusCounts->get('occupied', 0);

        $chartRows = collect(self::STATUS_META)
            ->map(fn ($meta, $status) => [
                'label' => $meta['label'],
                'color' => $meta['color'],
                'count' => $statusCounts->get($status, 0),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();

        $byLandlord = User::role('landlord')
            ->orderBy('name')
            ->get()
            ->map(function (User $landlord) {
                $houses = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlord->id))->get(['status']);
                $total = $houses->count();
                $occupied = $houses->where('status', 'occupied')->count();

                return (object) [
                    'landlord' => $landlord,
                    'total' => $total,
                    'occupied' => $occupied,
                    'vacant' => $houses->where('status', 'vacant')->count(),
                    'rate' => $total > 0 ? round($occupied / $total * 100, 1) : 0,
                ];
            });

        $byProperty = Property::withCount('blocks')
            ->with(['houses', 'landlord'])
            ->when($this->landlordId, fn ($q) => $q->where('landlord_id', $this->landlordId))
            ->orderBy('landlord_id')
            ->orderBy('name')
            ->get()
            ->map(function (Property $property) {
                $total = $property->houses->count();
                $occupied = $property->houses->where('status', 'occupied')->count();

                return (object) [
                    'property' => $property,
                    'total' => $total,
                    'occupied' => $occupied,
                    'rate' => $total > 0 ? round($occupied / $total * 100, 1) : 0,
                ];
            })
            ->groupBy(fn ($row) => $row->property->landlord->name);

        return view('livewire.admin.platform.occupancy', [
            'statusCounts' => $statusCounts,
            'totalUnits' => $totalUnits,
            'occupancyRate' => $totalUnits > 0 ? round($occupied / $totalUnits * 100, 1) : 0,
            'chartRows' => $chartRows,
            'byLandlord' => $byLandlord,
            'byProperty' => $byProperty,
            'landlords' => User::role('landlord')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
