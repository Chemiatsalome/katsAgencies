<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\House;
use App\Models\Income;
use App\Models\MaintenanceTask;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use Illuminate\Support\Collection;

class ReportService
{
    public function __construct(private readonly PropertyFinanceService $finance) {}

    /** @return array<string, string> */
    public function available(): array
    {
        return [
            'rent_collection' => 'Rent Collection',
            'arrears' => 'Arrears',
            'income' => 'Other Income',
            'expenses' => 'Expenses',
            'cash_flow' => 'Cash Flow',
            'property_performance' => 'Property Performance (ROI)',
            'occupancy' => 'Occupancy',
            'vacancy' => 'Vacancy',
            'maintenance_cost' => 'Maintenance Cost',
            'payment_history' => 'Payment History',
            'contract_expiry' => 'Contract Expiry',
            'deposits' => 'Deposits',
        ];
    }

    /** @return array{columns: string[], rows: array<int, array<int, mixed>>} */
    public function generate(string $type, int $landlordId, array $filters = []): array
    {
        $year = $filters['year'] ?? (int) now()->year;
        $month = $filters['month'] ?? null;

        return match ($type) {
            'rent_collection' => $this->rentCollection($landlordId, $year, $month),
            'arrears' => $this->arrears($landlordId),
            'income' => $this->income($landlordId, $year, $month),
            'expenses' => $this->expenses($landlordId, $year, $month),
            'cash_flow' => $this->cashFlow($landlordId),
            'property_performance' => $this->propertyPerformance($landlordId),
            'occupancy' => $this->occupancy($landlordId),
            'vacancy' => $this->vacancy($landlordId),
            'maintenance_cost' => $this->maintenanceCost($landlordId, $year, $month),
            'payment_history' => $this->paymentHistory($landlordId, $year, $month),
            'contract_expiry' => $this->contractExpiry($landlordId),
            'deposits' => $this->deposits($landlordId),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function propertyIdsFor(int $landlordId): Collection
    {
        return Property::forLandlord($landlordId)->pluck('id');
    }

    private function rentCollection(int $landlordId, int $year, ?int $month): array
    {
        $propertyIds = $this->propertyIdsFor($landlordId);
        $tenancyIds = $this->finance->tenancyIdsFor($propertyIds);

        $query = RentCharge::whereIn('tenancy_id', $tenancyIds)->where('period_year', $year)
            ->with('tenancy.tenant', 'tenancy.house.block.property');

        if ($month) {
            $query->where('period_month', $month);
        }

        $rows = $query->orderBy('due_date')->get()->map(fn (RentCharge $c) => [
            $c->tenancy->tenant->name,
            $c->tenancy->house->block->property->name.' — '.$c->tenancy->house->house_number,
            $c->period_year.'-'.str_pad((string) $c->period_month, 2, '0', STR_PAD_LEFT),
            $c->due_date->format('d M Y'),
            number_format((float) $c->amount_expected, 2),
            number_format((float) $c->amount_paid, 2),
            $c->status,
        ])->all();

        return ['columns' => ['Tenant', 'Property / House', 'Period', 'Due Date', 'Expected', 'Paid', 'Status'], 'rows' => $rows];
    }

    /**
     * One row per outstanding charge, same as before, but now split into
     * Gross Arrears / Advance Credit Applied / Net Arrears instead of one
     * raw "Arrears" figure — a tenant who overpaid a prior month showed up
     * here as owing the full gross amount even though Tenancy::balance()
     * and the Arrears page already knew better. Credit is applied oldest
     * charge first per tenancy (Tenancy::outstandingChargesWithCredit()),
     * the same order late-fee waiving already uses, and never crosses
     * between tenancies.
     */
    private function arrears(int $landlordId): array
    {
        $propertyIds = $this->propertyIdsFor($landlordId);
        $tenancyIds = $this->finance->tenancyIdsFor($propertyIds);

        $tenancies = Tenancy::whereIn('id', $tenancyIds)
            ->whereHas('rentCharges', fn ($q) => $q->outstanding())
            ->with('tenant', 'house.block.property')
            ->get();

        $rows = $tenancies->flatMap(fn (Tenancy $tenancy) => $tenancy->outstandingChargesWithCredit()
            ->map(fn (array $row) => [
                $tenancy->tenant->name,
                $tenancy->house->block->property->name.' — '.$tenancy->house->house_number,
                $row['charge']->period_year.'-'.str_pad((string) $row['charge']->period_month, 2, '0', STR_PAD_LEFT),
                $row['charge']->due_date->format('d M Y'),
                number_format($row['gross'], 2),
                number_format($row['credit_applied'], 2),
                number_format($row['net'], 2),
            ]))
            ->values()
            ->all();

        return ['columns' => ['Tenant', 'Property / House', 'Period', 'Due Date', 'Gross Arrears', 'Advance Credit Applied', 'Net Arrears'], 'rows' => $rows];
    }

    private function income(int $landlordId, int $year, ?int $month): array
    {
        $query = Income::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->whereYear('income_date', $year)
            ->with('property', 'category');

        if ($month) {
            $query->whereMonth('income_date', $month);
        }

        $rows = $query->orderBy('income_date')->get()->map(fn (Income $i) => [
            $i->income_date->format('d M Y'),
            $i->property->name,
            $i->category?->name ?? '—',
            $i->description ?: '—',
            number_format((float) $i->amount, 2),
        ])->all();

        return ['columns' => ['Date', 'Property', 'Category', 'Description', 'Amount'], 'rows' => $rows];
    }

    private function expenses(int $landlordId, int $year, ?int $month): array
    {
        $query = Expense::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->whereYear('expense_date', $year)
            ->with('property', 'category');

        if ($month) {
            $query->whereMonth('expense_date', $month);
        }

        $rows = $query->orderBy('expense_date')->get()->map(fn (Expense $e) => [
            $e->expense_date->format('d M Y'),
            $e->property->name,
            $e->category?->name ?? '—',
            $e->vendor ?: '—',
            number_format((float) $e->amount, 2),
        ])->all();

        return ['columns' => ['Date', 'Property', 'Category', 'Vendor', 'Amount'], 'rows' => $rows];
    }

    private function cashFlow(int $landlordId): array
    {
        $propertyIds = $this->propertyIdsFor($landlordId);
        $tenancyIds = $this->finance->tenancyIdsFor($propertyIds);

        $rows = collect(range(11, 0))->map(function (int $i) use ($propertyIds, $tenancyIds) {
            $date = now()->subMonths($i);
            $rent = $this->finance->collectedRent($tenancyIds, (int) $date->year, (int) $date->month);
            $other = $this->finance->otherIncome($propertyIds, $date->copy()->startOfMonth(), $date->copy()->endOfMonth());
            $expenses = $this->finance->expenses($propertyIds, $date->copy()->startOfMonth(), $date->copy()->endOfMonth());

            return [
                $date->format('M Y'),
                number_format($rent + $other, 2),
                number_format($expenses, 2),
                number_format($rent + $other - $expenses, 2),
            ];
        })->all();

        return ['columns' => ['Month', 'Income', 'Expenses', 'Net'], 'rows' => $rows];
    }

    private function propertyPerformance(int $landlordId): array
    {
        $rows = Property::forLandlord($landlordId)->get()->map(fn (Property $p) => [
            $p->name,
            $p->totalInvestment() > 0 ? number_format($p->totalInvestment(), 2) : '—',
            number_format($p->annualGrossIncome(), 2),
            number_format($p->annualExpenses(), 2),
            number_format($p->netOperatingIncome(), 2),
            $p->grossYield() !== null ? $p->grossYield().'%' : '—',
            $p->netYield() !== null ? $p->netYield().'%' : '—',
        ])->all();

        return ['columns' => ['Property', 'Total Investment', 'Annual Gross Income', 'Annual Expenses', 'Net Operating Income', 'Gross Yield', 'Net Yield'], 'rows' => $rows];
    }

    private function occupancy(int $landlordId): array
    {
        $rows = Property::forLandlord($landlordId)->with('houses')->get()->map(function (Property $p) {
            $total = $p->houses->count();
            $occupied = $p->houses->where('status', 'occupied')->count();

            return [
                $p->name,
                $total,
                $occupied,
                $total - $occupied,
                $total > 0 ? round($occupied / $total * 100, 1).'%' : '0%',
            ];
        })->all();

        return ['columns' => ['Property', 'Total Units', 'Occupied', 'Vacant', 'Occupancy Rate'], 'rows' => $rows];
    }

    private function vacancy(int $landlordId): array
    {
        $rows = House::whereHas('block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->vacant()
            ->with('block.property')
            ->get()
            ->map(function (House $house) {
                $lastTenancy = Tenancy::where('house_id', $house->id)->whereNotNull('move_out_date')->latest('move_out_date')->first();
                $vacantSince = $lastTenancy?->move_out_date ?? $house->created_at;
                $daysVacant = (int) now()->diffInDays($vacantSince, absolute: true);

                return [
                    $house->block->property->name,
                    $house->house_number,
                    $daysVacant,
                    number_format(round((float) $house->monthly_rent / 30 * $daysVacant, 2), 2),
                ];
            })->all();

        return ['columns' => ['Property', 'Unit', 'Days Vacant', 'Estimated Lost Rent'], 'rows' => $rows];
    }

    private function maintenanceCost(int $landlordId, int $year, ?int $month): array
    {
        $query = MaintenanceTask::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->whereNotNull('actual_cost')
            ->whereYear('completed_at', $year)
            ->with('property', 'vendor');

        if ($month) {
            $query->whereMonth('completed_at', $month);
        }

        $rows = $query->orderByDesc('completed_at')->get()->map(fn (MaintenanceTask $t) => [
            $t->completed_at?->format('d M Y') ?? '—',
            $t->property->name,
            $t->title,
            $t->category,
            $t->vendor?->name ?? '—',
            number_format((float) $t->actual_cost, 2),
        ])->all();

        return ['columns' => ['Completed', 'Property', 'Task', 'Category', 'Vendor', 'Actual Cost'], 'rows' => $rows];
    }

    private function paymentHistory(int $landlordId, int $year, ?int $month): array
    {
        // genuine() excludes opening-balance "assumed settled" backfill rows
        // — not real cash, and every other real-money figure in the app
        // (Cash Flow, Finance Overview, Dashboard) already excludes them.
        // Without this, exported payment history mixes fabricated entries
        // in with real transactions and won't reconcile with those totals.
        $query = Payment::genuine()
            ->whereHas('tenancy.house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->whereYear('payment_date', $year)
            ->with('tenancy.tenant', 'tenancy.house.block.property');

        if ($month) {
            $query->whereMonth('payment_date', $month);
        }

        $rows = $query->orderByDesc('payment_date')->get()->map(fn (Payment $p) => [
            $p->payment_date->format('d M Y'),
            $p->tenancy->tenant->name,
            $p->tenancy->house->block->property->name.' — '.$p->tenancy->house->house_number,
            $p->method,
            $p->reference_number ?: '—',
            number_format((float) $p->amount, 2),
        ])->all();

        return ['columns' => ['Date', 'Tenant', 'Property / House', 'Method', 'Reference', 'Amount'], 'rows' => $rows];
    }

    private function contractExpiry(int $landlordId): array
    {
        $rows = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->expiringSoon()
            ->with('tenant', 'house.block.property')
            ->orderBy('lease_end_date')
            ->get()
            ->map(fn (Tenancy $t) => [
                $t->tenant->name,
                $t->house->block->property->name.' — '.$t->house->house_number,
                $t->lease_end_date?->format('d M Y') ?? '—',
                $t->lease_end_date ? now()->diffInDays($t->lease_end_date, false) : '—',
            ])->all();

        return ['columns' => ['Tenant', 'Property / House', 'Lease End Date', 'Days Remaining'], 'rows' => $rows];
    }

    private function deposits(int $landlordId): array
    {
        $rows = Tenancy::whereHas('house.block.property', fn ($q) => $q->where('landlord_id', $landlordId))
            ->with('tenant', 'house.block.property', 'depositPayments', 'depositRefunds')
            ->get()
            ->map(function (Tenancy $t) {
                $paid = (float) $t->depositPayments->sum('amount');

                return [
                    $t->tenant->name,
                    $t->house->block->property->name.' — '.$t->house->house_number,
                    number_format((float) $t->deposit_required, 2),
                    number_format($paid, 2),
                    number_format($t->depositRefundedTotal(), 2),
                    number_format($paid - $t->depositRefundedTotal() - $t->depositDeductedTotal(), 2),
                ];
            })->all();

        return ['columns' => ['Tenant', 'Property / House', 'Required', 'Paid', 'Refunded', 'Held Balance'], 'rows' => $rows];
    }
}
