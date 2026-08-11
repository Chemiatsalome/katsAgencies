<?php

namespace App\Livewire\Analytics;

use App\Models\Property;
use App\Services\PropertyFinanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Performance extends Component
{
    public function render()
    {
        $landlordId = Auth::id();
        $service = app(PropertyFinanceService::class);

        $rows = Property::forLandlord($landlordId)->with('houses')->orderBy('name')->get()->map(function (Property $property) use ($service) {
            $houseIds = $property->houses->pluck('id');
            $tenancyIds = $service->tenancyIdsFor(collect([$property->id]));

            return (object) [
                'property' => $property,
                'investment' => $property->totalInvestment(),
                'currentValue' => (float) $property->current_estimated_value,
                'annualIncome' => $property->annualGrossIncome(),
                'annualExpenses' => $property->annualExpenses(),
                'netIncome' => $property->netOperatingIncome(),
                'grossYield' => $property->grossYield(),
                'netYield' => $property->netYield(),
                'occupancyRate' => $service->occupancyRate($houseIds),
                'collectionRate' => $service->collectionRate($tenancyIds, (int) now()->year, (int) now()->month),
                'health' => $service->healthScore($property),
            ];
        });

        return view('livewire.analytics.performance', [
            'rows' => $rows,
            'portfolioInvestment' => $rows->sum('investment'),
            'portfolioIncome' => $rows->sum('annualIncome'),
            'portfolioExpenses' => $rows->sum('annualExpenses'),
            'portfolioNetIncome' => $rows->sum('netIncome'),
        ]);
    }
}
