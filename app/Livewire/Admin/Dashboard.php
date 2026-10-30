<?php

namespace App\Livewire\Admin;

use App\Models\House;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\PropertyFinanceService;
use App\Services\RentScheduleService;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public function render()
    {
        $rentSchedule = app(RentScheduleService::class);
        $finance = app(PropertyFinanceService::class);

        // Rent obligations are derived from active tenancies, not manually
        // entered — make sure this period's charges exist across every
        // landlord before summing, so the platform totals reflect real data.
        $rentSchedule->ensureChargesForPeriod(null, null, now()->year, now()->month);
        $lastMonthDate = now()->subMonthNoOverflow();
        $rentSchedule->ensureChargesForPeriod(null, null, $lastMonthDate->year, $lastMonthDate->month);

        $thisMonth = RentCharge::forPeriod(now()->year, now()->month)->get();
        $tenancyIds = Tenancy::pluck('id');

        // Deliberately excludes opening_balance (the "assume settled" guess
        // for pre-tracking history) — that's not real cash, and mixing it in
        // would distort both this total and the month-over-month trend.
        $collectedThisMonth = $finance->collectedRent($tenancyIds, (int) now()->year, (int) now()->month);

        // The trend compares like with like: collections through today's
        // day-of-month last month, not the full prior month — otherwise an
        // in-progress month always looks like a decline against a complete
        // one, regardless of actual pace.
        $collectedLastMonthToDate = $finance->collectedRent(
            $tenancyIds, (int) $lastMonthDate->year, (int) $lastMonthDate->month, throughDay: (int) now()->day
        );
        $collectedTrend = $collectedLastMonthToDate > 0
            ? round((($collectedThisMonth - $collectedLastMonthToDate) / $collectedLastMonthToDate) * 100, 1)
            : null;

        // Genuinely outstanding — a charge settled via opening_balance already
        // carries status "paid" and is correctly excluded here, independent
        // of which payment source funded it.
        //
        // Deliberately NOT netted against Tenancy::advanceCreditAvailable()
        // (see PropertyFinanceService::outstanding(), the all-time "what's
        // owed right now" figure used elsewhere) — this is THIS calendar
        // month's own shortfall specifically, for a single-period platform
        // snapshot. A surplus sitting on a different month's charge is a
        // different concept from "how much of this month's billing is
        // uncollected," the same reasoning Admin\Platform\Overview's
        // monthlyTotals() documents for the identical period-specific figure.
        $outstandingThisMonth = $thisMonth
            ->filter(fn (RentCharge $c) => in_array($c->status, ['unpaid', 'partial', 'overdue'], true) && $c->due_date->lte(now()))
            ->sum(fn (RentCharge $c) => (float) $c->amount_expected - (float) $c->amount_paid);

        $totalUnits = House::count();
        $occupiedUnits = House::occupied()->count();

        return view('livewire.admin.dashboard', [
            'totalLandlords' => User::role('landlord')->count(),
            'activeLandlords' => User::role('landlord')->where('status', 'active')->count(),
            'pendingApplications' => User::role('landlord')->where('status', 'inactive')->whereNull('approved_at')->count(),
            'suspendedLandlords' => User::role('landlord')->where('status', 'suspended')->count(),

            'totalProperties' => Property::count(),
            'totalUnits' => $totalUnits,
            'occupiedUnits' => $occupiedUnits,
            'vacantUnits' => House::vacant()->count(),
            'occupancyRate' => $totalUnits > 0 ? round($occupiedUnits / $totalUnits * 100, 1) : 0,
            'activeTenancies' => Tenancy::active()->count(),

            'expectedRent' => (float) $thisMonth->sum('amount_expected'),
            'collectedRent' => $collectedThisMonth,
            'outstandingRent' => $outstandingThisMonth,
            'collectedTrend' => $collectedTrend,

            'recentLandlords' => User::role('landlord')->latest()->limit(5)->get(),
        ]);
    }
}
