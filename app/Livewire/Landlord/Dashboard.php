<?php

namespace App\Livewire\Landlord;

use App\Models\Complaint;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Services\PropertyFinanceService;
use App\Services\RentScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public string $propertyId = 'all';

    public string $period = 'this_month';

    public string $customFrom = '';

    public string $customTo = '';

    public function mount(): void
    {
        $this->customFrom = now()->startOfMonth()->toDateString();
        $this->customTo = now()->toDateString();
    }

    private function periodRange(): array
    {
        return match ($this->period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [now()->subMonths(2)->startOfMonth(), now()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfYear()],
            'custom' => [Carbon::parse($this->customFrom), Carbon::parse($this->customTo)],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    public function render()
    {
        $landlordId = Auth::id();
        $service = new PropertyFinanceService;

        $propertiesQuery = Property::forLandlord($landlordId);
        $properties = $propertiesQuery->get(['id', 'name']);

        $propertyIds = $this->propertyId === 'all'
            ? $properties->pluck('id')
            : $properties->pluck('id')->filter(fn ($id) => (string) $id === $this->propertyId)->values();

        $houseIds = House::whereHas('block', fn ($q) => $q->whereIn('property_id', $propertyIds))->pluck('id');
        $tenancyIds = $service->tenancyIdsFor($propertyIds);

        [$from, $to] = $this->periodRange();

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, null, (int) now()->year, (int) now()->month);

        $expected = $service->expectedRent($tenancyIds, (int) now()->year, (int) now()->month);
        $collected = $service->collectedRent($tenancyIds, (int) now()->year, (int) now()->month);
        $outstanding = $service->outstanding($tenancyIds);
        $expenses = $service->expenses($propertyIds, $from, $to);
        $otherIncome = $service->otherIncome($propertyIds, $from, $to);

        // Same day-of-month cutoff as "this month" so an in-progress month
        // isn't compared against a complete prior one — that comparison
        // always shows a false decline early in any month regardless of
        // actual pace, since fewer days have elapsed.
        $lastMonthSameDay = now()->subMonthNoOverflow()->startOfMonth()->addDays(min(now()->day, now()->subMonthNoOverflow()->daysInMonth) - 1);
        $lastMonthExpenses = $service->expenses($propertyIds, now()->subMonthNoOverflow()->startOfMonth(), $lastMonthSameDay);
        $expenseTrend = $lastMonthExpenses > 0 ? round((($expenses - $lastMonthExpenses) / $lastMonthExpenses) * 100, 1) : null;

        $overdueCharges = RentCharge::overdue()->whereIn('tenancy_id', $tenancyIds)->get();
        $dueSoonCharges = RentCharge::outstanding()
            ->whereIn('tenancy_id', $tenancyIds)
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(3)->toDateString()])
            ->get();
        $dueTodayCharges = RentCharge::outstanding()->whereIn('tenancy_id', $tenancyIds)->whereDate('due_date', now())->get();

        $expiringContracts = Tenancy::whereIn('house_id', $houseIds)->expiringSoon(30)->count();

        $emergencyMaintenance = MaintenanceTask::whereIn('property_id', $propertyIds)->open()->where('priority', 'emergency')->count();
        $openMaintenance = MaintenanceTask::whereIn('property_id', $propertyIds)->open()->count();
        $scheduledToday = MaintenanceTask::whereIn('property_id', $propertyIds)->scheduledOn(now()->toDateString())->count();
        $pendingComplaints = Complaint::where(function ($q) use ($propertyIds) {
            $q->whereIn('property_id', $propertyIds)
                ->orWhereHas('house.block', fn ($q2) => $q2->whereIn('property_id', $propertyIds));
        })->unresolved()->count();

        $vacantCount = House::whereIn('id', $houseIds)->vacant()->count();
        $occupiedCount = House::whereIn('id', $houseIds)->occupied()->count();
        $totalUnits = $houseIds->count();

        return view('livewire.landlord.dashboard', [
            'properties' => $properties,
            'totalProperties' => $properties->count(),
            'totalUnits' => $totalUnits,
            'occupiedUnits' => $occupiedCount,
            'vacantUnits' => $vacantCount,
            'occupancyRate' => $totalUnits > 0 ? round($occupiedCount / $totalUnits * 100, 1) : 0,

            'expected' => $expected,
            'collected' => $collected,
            'outstanding' => $outstanding,
            'expenses' => $expenses,
            'otherIncome' => $otherIncome,
            'netIncome' => $collected + $otherIncome - $expenses,
            'expenseTrend' => $expenseTrend,

            'overdueCount' => $overdueCharges->pluck('tenancy_id')->unique()->count(),
            'overdueAmount' => $overdueCharges->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid),
            'dueSoonCount' => $dueSoonCharges->pluck('tenancy_id')->unique()->count(),
            'expiringContracts' => $expiringContracts,
            'emergencyMaintenance' => $emergencyMaintenance,
            'openMaintenance' => $openMaintenance,
            'pendingComplaints' => $pendingComplaints,

            'dueTodayAmount' => $dueTodayCharges->sum('amount_expected'),
            'scheduledToday' => $scheduledToday,

            'totalTenants' => Tenant::whereHas('tenancies', fn ($q) => $q->whereIn('id', $tenancyIds))->count(),
        ]);
    }
}
