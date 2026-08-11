<?php

namespace App\Livewire\Admin\Platform;

use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PropertyFinanceService;
use App\Services\RentScheduleService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Overview extends Component
{
    public ?int $landlordId = null;

    #[Url]
    public int $year;

    public function mount(): void
    {
        $this->year = now()->year;
    }

    private function tenancyIdsFor(?int $landlordId)
    {
        return Tenancy::query()
            ->when($landlordId, fn ($q) => $q->whereHas(
                'house.block.property',
                fn ($q2) => $q2->where('landlord_id', $landlordId)
            ))
            ->pluck('id');
    }

    private function monthlyTotals(int $year, ?int $landlordId): array
    {
        $rentSchedule = app(RentScheduleService::class);
        $finance = app(PropertyFinanceService::class);
        $tenancyIds = $this->tenancyIdsFor($landlordId);

        return collect(range(1, 12))->map(function (int $month) use ($year, $tenancyIds, $rentSchedule, $finance, $landlordId) {
            $rentSchedule->ensureChargesForPeriod($landlordId, null, $year, $month);

            $expected = $finance->expectedRent($tenancyIds, $year, $month);
            // Real cash only — excludes opening_balance (the "assume settled"
            // guess for pre-tracking history), which would otherwise inflate
            // this figure and distort the collection rate.
            $collected = $finance->collectedRent($tenancyIds, $year, $month);
            // A charge settled via opening_balance already carries status
            // "paid" and is correctly excluded here — this is NOT simply
            // expected minus collected, which would double back into arrears
            // that were never real.
            $outstanding = (float) RentCharge::outstanding()
                ->forPeriod($year, $month)
                ->whereIn('tenancy_id', $tenancyIds)
                ->get()
                ->sum(fn (RentCharge $c) => (float) $c->amount_expected - (float) $c->amount_paid);

            return [
                'label' => Carbon::create($year, $month)->format('M Y'),
                'expected' => $expected,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'rate' => $expected > 0 ? round($collected / $expected * 100, 1) : 0,
            ];
        })->all();
    }

    public function render()
    {
        $months = $this->monthlyTotals($this->year, $this->landlordId);
        $tenancyIds = $this->tenancyIdsFor($this->landlordId);

        $totalArrears = (float) RentCharge::outstanding()
            ->whereIn('tenancy_id', $tenancyIds)
            ->get()
            ->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);

        $totalExpected = array_sum(array_column($months, 'expected'));
        $totalCollected = array_sum(array_column($months, 'collected'));

        $byLandlord = User::role('landlord')
            ->orderBy('name')
            ->get()
            ->map(function (User $landlord) use ($months) {
                $landlordMonths = $this->monthlyTotals($this->year, $landlord->id);

                $expected = array_sum(array_column($landlordMonths, 'expected'));
                $collected = array_sum(array_column($landlordMonths, 'collected'));

                $tenancyIds = $this->tenancyIdsFor($landlord->id);
                $outstanding = (float) RentCharge::outstanding()
                    ->whereIn('tenancy_id', $tenancyIds)
                    ->get()
                    ->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);

                return (object) [
                    'landlord' => $landlord,
                    'expected' => $expected,
                    'collected' => $collected,
                    'outstanding' => $outstanding,
                    'rate' => $expected > 0 ? round($collected / $expected * 100, 1) : 0,
                ];
            });

        return view('livewire.admin.platform.overview', [
            'months' => collect($months),
            'totalExpected' => $totalExpected,
            'totalCollected' => $totalCollected,
            'totalArrears' => $totalArrears,
            'collectionRate' => $totalExpected > 0 ? round($totalCollected / $totalExpected * 100, 1) : 0,
            'byLandlord' => $byLandlord,
            'landlords' => User::role('landlord')->orderBy('name')->get(['id', 'name']),
            'availableYears' => app(RentScheduleService::class)->availableYears(null),
        ]);
    }
}
