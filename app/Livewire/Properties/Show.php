<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Services\PropertyFinanceService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Show extends Component
{
    public Property $property;

    #[Url]
    public string $tab = 'overview';

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);
        $this->property = $property;
    }

    public function render()
    {
        $service = new PropertyFinanceService;
        $houseIds = $this->property->houses()->pluck('houses.id');
        $tenancyIds = $service->tenancyIdsFor(collect([$this->property->id]));

        $data = [
            'houseIds' => $houseIds,
            'totalUnits' => $houseIds->count(),
            'occupiedUnits' => \App\Models\House::whereIn('id', $houseIds)->occupied()->count(),
            'vacantUnits' => \App\Models\House::whereIn('id', $houseIds)->vacant()->count(),
            'occupancyRate' => $service->occupancyRate($houseIds),
            'expectedRent' => $service->expectedRent($tenancyIds, (int) now()->year, (int) now()->month),
            'collectedRent' => $service->collectedRent($tenancyIds, (int) now()->year, (int) now()->month),
            'outstanding' => $service->outstanding($tenancyIds),
            'expenses' => $service->expenses(collect([$this->property->id]), now()->startOfMonth(), now()->endOfMonth()),
        ];
        $data['netIncome'] = $data['collectedRent'] - $data['expenses'];

        match ($this->tab) {
            'units' => $data['houses'] = $this->property->houses()->with('currentTenancy.tenant')->orderBy('house_number')->get(),
            'tenants' => $data['tenancies'] = \App\Models\Tenancy::whereIn('house_id', $houseIds)->active()->with(['tenant', 'house'])->get(),
            'rent' => $data['rentCharges'] = \App\Models\RentCharge::whereIn('tenancy_id', $tenancyIds)->with('tenancy.tenant')->latest('due_date')->limit(50)->get(),
            'expenses' => $data['expenseList'] = $this->property->expenses()->with('category')->latest('expense_date')->limit(50)->get(),
            'maintenance' => $data['tasks'] = $this->property->maintenanceTasks()->with('vendor')->latest()->limit(50)->get(),
            'contracts' => $data['tenancies'] = \App\Models\Tenancy::whereIn('house_id', $houseIds)->with(['tenant', 'house'])->latest()->get(),
            'documents' => $data['documents'] = $this->property->documents()->latest()->get(),
            'performance' => $data['health'] = $service->healthScore($this->property),
            default => null,
        };

        return view('livewire.properties.show', $data);
    }
}
