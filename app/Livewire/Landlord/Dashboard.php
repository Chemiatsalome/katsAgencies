<?php

namespace App\Livewire\Landlord;

use App\Models\Complaint;
use App\Models\House;
use App\Models\LandlordSetting;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Services\DisbursementService;
use App\Services\PaymentGatewayService;
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
        $user = Auth::user();
        $service = new PropertyFinanceService;

        // These fields feed directly into every system-generated tenancy
        // agreement (as the landlord/lessor details a tenant signs against),
        // so a landlord who hasn't filled them in yet needs to know before
        // they send a contract, not after a tenant complains it's blank.
        // Named specifically rather than a generic "phone or address" —
        // telling a landlord who already has one of the two filled in that
        // it's "missing" just sends them hunting for a problem that isn't
        // there.
        $missingProfileFields = array_filter([
            blank($user->phone) ? 'phone number' : null,
            blank(LandlordSetting::get($landlordId, 'contract_landlord_address')) ? 'address' : null,
        ]);
        $profileIncomplete = $missingProfileFields !== [];

        $propertiesQuery = Property::forLandlord($landlordId);
        $properties = $propertiesQuery->get(['id', 'name']);

        $propertyIds = $this->propertyId === 'all'
            ? $properties->pluck('id')
            : $properties->pluck('id')->filter(fn ($id) => (string) $id === $this->propertyId)->values();

        $houseIds = House::whereHas('block', fn ($q) => $q->whereIn('property_id', $propertyIds))->pluck('id');
        $tenancyIds = $service->tenancyIdsFor($propertyIds);

        [$from, $to] = $this->periodRange();

        app(RentScheduleService::class)->ensureChargesForPeriod($landlordId, null, (int) now()->year, (int) now()->month);

        // Opportunistic, not just the nightly scheduled command — mirrors
        // ensureChargesForPeriod() above. Without this, a landlord who just
        // turned late fees on in Settings would see nothing change until
        // whatever next runs the scheduler (which, outside a configured
        // cron/supervisor process, may be never).
        app(RentScheduleService::class)->applyLateFees($landlordId);

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
        // One shared, non-double-counted pass — both amounts below filter
        // this same breakdown rather than each running their own separate
        // credit-netting query (see outstandingChargeBreakdown()'s docblock
        // for why that would overstate how far one tenant's credit goes).
        // Counts above stay on the raw charges — a charge fully covered by
        // credit is still genuinely overdue in the ledger; only the amount
        // still actually owed is corrected here, not whether it counts.
        $chargeBreakdown = $service->outstandingChargeBreakdown($tenancyIds);
        $overdueAmount = $chargeBreakdown->filter(fn (array $r) => $r['charge']->status === 'overdue')->sum('net');
        $dueTodayAmount = $chargeBreakdown->filter(fn (array $r) => $r['charge']->due_date->isToday())->sum('net');

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

        $lateFeesThisMonth = RentCharge::whereIn('tenancy_id', $tenancyIds)
            ->whereNotNull('late_fee_charged_at')
            ->whereYear('late_fee_charged_at', now()->year)
            ->whereMonth('late_fee_charged_at', now()->month)
            ->sum('late_fee_amount');

        // Deposit obligations are deliberately computed and shown separately
        // from rent above — a tenant whose rent balance is zero must not
        // look fully settled here if their deposit still falls short.
        $depositsOutstanding = Tenancy::whereIn('id', $tenancyIds)->get()->map(fn (Tenancy $t) => $t->depositOutstanding())->filter(fn ($amount) => $amount > 0);

        return view('livewire.landlord.dashboard', [
            'profileIncomplete' => $profileIncomplete,
            'missingProfileFields' => $missingProfileFields,
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
            'overdueAmount' => $overdueAmount,
            'dueSoonCount' => $dueSoonCharges->pluck('tenancy_id')->unique()->count(),
            'expiringContracts' => $expiringContracts,
            'emergencyMaintenance' => $emergencyMaintenance,
            'openMaintenance' => $openMaintenance,
            'pendingComplaints' => $pendingComplaints,

            'dueTodayAmount' => $dueTodayAmount,
            'scheduledToday' => $scheduledToday,

            'totalTenants' => Tenant::whereHas('tenancies', fn ($q) => $q->whereIn('id', $tenancyIds))->count(),

            'lateFeesThisMonth' => $lateFeesThisMonth,
            'lateFeesEnabled' => LandlordSetting::get($landlordId, 'late_fee_enabled', '0') === '1',

            'depositOutstandingTotal' => $depositsOutstanding->sum(),
            'depositOutstandingCount' => $depositsOutstanding->count(),

            // Money is sensitive — every landlord should understand exactly
            // where their rent goes before they ever collect any, not
            // discover it after the fact.
            'directCollectionConfigured' => app(PaymentGatewayService::class)->isDirectlyConfigured($landlordId),
            'heldPendingDisbursement' => app(DisbursementService::class)->owed($landlordId),
        ]);
    }
}
