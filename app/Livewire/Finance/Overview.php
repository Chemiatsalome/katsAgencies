<?php

namespace App\Livewire\Finance;

use App\Models\Property;
use App\Services\DisbursementService;
use App\Services\PaymentGatewayService;
use App\Services\PropertyFinanceService;
use App\Services\RentScheduleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Overview extends Component
{
    #[Url]
    public string $propertyId = 'all';

    // No manager permission unlocks this page — financial overview stays
    // landlord-only regardless of what else a manager has been granted.
    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('landlord'), 403);
    }

    public function render()
    {
        $landlordId = Auth::id();
        $properties = Property::forLandlord($landlordId)->orderBy('name')->get();

        $propertyIds = $this->propertyId === 'all'
            ? $properties->pluck('id')
            : collect([(int) $this->propertyId]);

        $service = app(PropertyFinanceService::class);
        $tenancyIds = $service->tenancyIdsFor($propertyIds);

        $rentSchedule = app(RentScheduleService::class);
        $propertyFilter = $this->propertyId === 'all' ? null : (int) $this->propertyId;

        $months = collect(range(5, 0))->map(function (int $i) use ($service, $tenancyIds, $propertyIds, $rentSchedule, $landlordId, $propertyFilter) {
            $date = now()->subMonths($i);
            $rentSchedule->ensureChargesForPeriod($landlordId, $propertyFilter, (int) $date->year, (int) $date->month);
            $rent = $service->collectedRent($tenancyIds, (int) $date->year, (int) $date->month);
            $other = $service->otherIncome($propertyIds, $date->copy()->startOfMonth(), $date->copy()->endOfMonth());
            $expenses = $service->expenses($propertyIds, $date->copy()->startOfMonth(), $date->copy()->endOfMonth());
            $mpesa = $service->collectedViaMpesa($tenancyIds, (int) $date->year, (int) $date->month);

            return [
                'label' => $date->format('M Y'),
                'income' => round($rent + $other, 2),
                'expenses' => round($expenses, 2),
                'net' => round($rent + $other - $expenses, 2),
                'mpesa' => round($mpesa, 2),
            ];
        });

        $disbursements = app(DisbursementService::class);

        return view('livewire.finance.overview', [
            'properties' => $properties,
            'months' => $months,
            'totalIncome' => $months->sum('income'),
            'totalExpenses' => $months->sum('expenses'),
            'netCashflow' => $months->sum('net'),
            'outstanding' => $service->outstanding($tenancyIds),
            'totalMpesaCollected' => $months->sum('mpesa'),
            // All-time, not scoped to the property filter or the 6-month
            // window above — disbursement is a landlord-level running
            // balance between Kats and the landlord, not per-property.
            'allTimeMpesaCollected' => $disbursements->collectedViaMpesa($landlordId),
            'allTimeCollectedDirectly' => $disbursements->collectedDirectly($landlordId),
            'heldPendingDisbursement' => $disbursements->owed($landlordId),
            'daysUntilNextRelease' => $disbursements->daysUntilNextRelease(),
            'directCollectionConfigured' => app(PaymentGatewayService::class)->isDirectlyConfigured($landlordId),
        ]);
    }
}
