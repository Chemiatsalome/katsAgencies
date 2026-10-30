<?php

namespace App\Livewire\Admin\Reports;

use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class LandlordReport extends Component
{
    public ?int $landlordId = null;

    public function export()
    {
        $landlord = User::approvedLandlords()->with('properties.blocks.houses.currentTenancy.tenant')->findOrFail($this->landlordId);

        return response()->streamDownload(function () use ($landlord) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Property', 'Block', 'Unit', 'Type', 'Monthly Rent', 'Status', 'Current Tenant']);

            foreach ($landlord->properties as $property) {
                foreach ($property->blocks as $block) {
                    foreach ($block->houses as $house) {
                        fputcsv($out, [
                            $property->name,
                            $block->name,
                            $house->house_number,
                            $house->type,
                            $house->monthly_rent,
                            $house->status,
                            $house->currentTenancy?->tenant->name ?? '',
                        ]);
                    }
                }
            }

            fclose($out);
        }, str($landlord->name)->slug().'-report-'.now()->format('Y-m-d').'.csv');
    }

    public function render()
    {
        return view('livewire.admin.reports.landlord', [
            'landlords' => User::approvedLandlords()->orderBy('name')->get(),
        ]);
    }
}
