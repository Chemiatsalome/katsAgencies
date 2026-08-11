<?php

namespace App\Livewire\Admin\Reports;

use App\Models\House;
use App\Models\Property;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Platform extends Component
{
    public function exportLandlordSummary()
    {
        $landlords = User::role('landlord')->withCount('properties')->with('properties.blocks.houses')->get();

        return response()->streamDownload(function () use ($landlords) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Landlord', 'Email', 'Status', 'Properties', 'Units', 'Occupied', 'Vacant']);

            foreach ($landlords as $landlord) {
                $houses = $landlord->properties->flatMap(fn ($p) => $p->blocks->flatMap(fn ($b) => $b->houses));
                fputcsv($out, [
                    $landlord->name,
                    $landlord->email,
                    $landlord->status,
                    $landlord->properties_count,
                    $houses->count(),
                    $houses->where('status', 'occupied')->count(),
                    $houses->where('status', 'vacant')->count(),
                ]);
            }

            fclose($out);
        }, 'landlord-summary-'.now()->format('Y-m-d').'.csv');
    }

    public function exportOccupancy()
    {
        $properties = Property::with(['landlord', 'houses'])->orderBy('name')->get();

        return response()->streamDownload(function () use ($properties) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Property', 'Landlord', 'Total Units', 'Occupied', 'Vacant', 'Occupancy Rate']);

            foreach ($properties as $property) {
                $total = $property->houses->count();
                $occupied = $property->houses->where('status', 'occupied')->count();
                fputcsv($out, [
                    $property->name,
                    $property->landlord->name,
                    $total,
                    $occupied,
                    $property->houses->where('status', 'vacant')->count(),
                    $total > 0 ? round($occupied / $total * 100, 1).'%' : '0%',
                ]);
            }

            fclose($out);
        }, 'occupancy-report-'.now()->format('Y-m-d').'.csv');
    }

    public function render()
    {
        return view('livewire.admin.reports.platform', [
            'totalLandlords' => User::role('landlord')->count(),
            'totalProperties' => Property::count(),
            'totalUnits' => House::count(),
        ]);
    }
}
