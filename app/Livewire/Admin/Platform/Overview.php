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
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Overview extends Component
{
    use WithPagination;

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
            //
            // Deliberately NOT netted against Tenancy::advanceCreditAvailable()
            // the way the real-time "what's owed right now" figures below are
            // (totalArrears, per-landlord outstanding, PropertyFinanceService::
            // outstanding()) — this is a different metric. It's this specific
            // period's own shortfall for a historical trend chart; crediting a
            // later month's surplus back onto an earlier month's bar would
            // make a past month's chart retroactively change as later data
            // comes in, which isn't what "how did period X perform" means.
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

        // All-time, right-now arrears — unlike monthlyTotals()'s per-period
        // figure above, this one IS the same "what's actually still owed"
        // concept as Tenancy::balance() / Arrears\Index, so it reuses the
        // already-netted, already-tested shared calculation instead of its
        // own raw (and previously un-netted) duplicate.
        $totalArrears = app(PropertyFinanceService::class)->outstanding($tenancyIds);

        $totalExpected = array_sum(array_column($months, 'expected'));
        $totalCollected = array_sum(array_column($months, 'collected'));

        // Paginated before the per-landlord totals are computed, not after
        // — each row needs a full year of monthlyTotals() built just for
        // it, so only computing that for the 15 landlords actually shown
        // (via ->through()) avoids doing the same work for every other
        // landlord on the platform on every single render.
        $byLandlord = User::approvedLandlords()
            ->orderBy('name')
            ->paginate(15, ['*'], 'landlordPage')
            ->through(function (User $landlord) {
                $landlordMonths = $this->monthlyTotals($this->year, $landlord->id);

                $expected = array_sum(array_column($landlordMonths, 'expected'));
                $collected = array_sum(array_column($landlordMonths, 'collected'));

                $tenancyIds = $this->tenancyIdsFor($landlord->id);
                $outstanding = app(PropertyFinanceService::class)->outstanding($tenancyIds);

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
            'landlords' => User::approvedLandlords()->orderBy('name')->get(['id', 'name']),
            'availableYears' => app(RentScheduleService::class)->availableYears(null),
        ]);
    }
}
