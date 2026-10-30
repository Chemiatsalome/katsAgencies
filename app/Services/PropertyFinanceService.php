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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PropertyFinanceService
{
    /** All tenancy IDs (any status) for the given property IDs. */
    public function tenancyIdsFor(Collection $propertyIds): Collection
    {
        return Tenancy::whereHas('house.block', fn ($q) => $q->whereIn('property_id', $propertyIds))->pluck('id');
    }

    public function expectedRent(Collection $tenancyIds, int $year, int $month): float
    {
        return (float) RentCharge::forPeriod($year, $month)->whereIn('tenancy_id', $tenancyIds)->sum('amount_expected');
    }

    /**
     * Real money collected for the period — deliberately excludes
     * `opening_balance` payments (the system's "assume settled" guess for
     * pre-tracking history), which are not cash and would otherwise inflate
     * this total and distort any month-over-month trend built on it.
     *
     * $throughDay caps the sum at that day-of-month (inclusive) — pass the
     * current day-of-month when comparing against an in-progress month, so
     * a complete prior month isn't compared against a partial current one
     * (which would always show a misleading decline early in any month).
     * Clamped to the target month's actual length (e.g. day 31 against
     * February becomes the 28th/29th).
     */
    public function collectedRent(Collection $tenancyIds, int $year, int $month, ?int $throughDay = null): float
    {
        return (float) Payment::genuine()
            ->whereIn('tenancy_id', $tenancyIds)
            ->whereHas('rentCharge', fn ($q) => $q->forPeriod($year, $month))
            ->when($throughDay, function ($q) use ($year, $month, $throughDay) {
                $cutoff = Carbon::create($year, $month, min($throughDay, Carbon::create($year, $month)->daysInMonth));
                $q->where('payment_date', '<=', $cutoff);
            })
            ->sum('amount');
    }

    /**
     * Rent collected for the period specifically via M-Pesa — i.e. money that
     * landed in Kats Agencies' own paybill and is held pending disbursement
     * to the landlord, as distinct from cash/bank rent the landlord already
     * has in hand directly. Excludes `opening_balance` the same as
     * collectedRent() for the same reason.
     */
    public function collectedViaMpesa(Collection $tenancyIds, int $year, int $month): float
    {
        return (float) Payment::genuine()
            ->whereIn('tenancy_id', $tenancyIds)
            ->where('method', 'mpesa')
            ->whereHas('rentCharge', fn ($q) => $q->forPeriod($year, $month))
            ->sum('amount');
    }

    /**
     * The canonical, non-double-counted breakdown behind every "overdue
     * total / due-today total / monthly outstanding" figure across these
     * tenancies: each tenancy's own available credit is consumed exactly
     * ONCE, oldest outstanding charge first (Tenancy::outstandingChargesWithCredit()),
     * and every caller below filters/buckets THIS one pass rather than
     * running its own separate netting query — if "overdue amount" and
     * "due today amount" each independently netted the same tenant's
     * credit against their own narrower charge subset, the same credit
     * could appear to cover both figures at once, overstating how far it
     * actually goes.
     *
     * Only loads tenancies that actually have an outstanding charge — fine
     * at landlord-portfolio scale (where this is used today); flagged as a
     * revisit if it's ever pointed at the whole platform at once.
     *
     * @return Collection<int, array{charge: RentCharge, tenancy_id: int, gross: float, credit_applied: float, net: float}>
     */
    public function outstandingChargeBreakdown(Collection $tenancyIds): Collection
    {
        return Tenancy::whereIn('id', $tenancyIds)
            ->whereHas('rentCharges', fn ($q) => $q->outstanding())
            ->get()
            ->flatMap(fn (Tenancy $tenancy) => $tenancy->outstandingChargesWithCredit()
                ->map(fn (array $row) => $row + ['tenancy_id' => $tenancy->id]))
            ->values();
    }

    /**
     * Netted against each tenancy's own available advance credit before
     * summing — mirrors Tenancy::balance() / Arrears\Index's existing
     * netting so this aggregate (feeds Finance Overview, Landlord/Admin
     * Platform dashboards, Properties\Show, Analytics\Performance) never
     * disagrees with what a tenant's own page shows. Netted per tenancy,
     * never pooled across tenancies — one tenant's surplus must never
     * reduce a different tenant's genuine arrears.
     */
    public function outstanding(Collection $tenancyIds): float
    {
        $grossByTenancy = RentCharge::outstanding()
            ->whereIn('tenancy_id', $tenancyIds)
            ->selectRaw('tenancy_id, SUM(amount_expected - amount_paid) as gross')
            ->groupBy('tenancy_id')
            ->pluck('gross', 'tenancy_id');

        $creditByTenancy = RentCharge::where('status', 'advance')
            ->whereIn('tenancy_id', $tenancyIds)
            ->selectRaw('tenancy_id, SUM(amount_paid - amount_expected) as credit')
            ->groupBy('tenancy_id')
            ->pluck('credit', 'tenancy_id');

        $total = 0.0;

        foreach ($grossByTenancy as $tenancyId => $gross) {
            $credit = (float) ($creditByTenancy[$tenancyId] ?? 0);
            $total += max(0, (float) $gross - $credit);
        }

        return $total;
    }

    public function expenses(Collection $propertyIds, \DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) Expense::whereIn('property_id', $propertyIds)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');
    }

    public function otherIncome(Collection $propertyIds, \DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) Income::whereIn('property_id', $propertyIds)
            ->whereBetween('income_date', [$from, $to])
            ->sum('amount');
    }

    public function occupancyRate(Collection $houseIds): float
    {
        $total = $houseIds->count();
        if ($total === 0) {
            return 0.0;
        }

        $occupied = House::whereIn('id', $houseIds)->occupied()->count();

        return round($occupied / $total * 100, 1);
    }

    public function collectionRate(Collection $tenancyIds, int $year, int $month): float
    {
        $expected = $this->expectedRent($tenancyIds, $year, $month);

        return $expected > 0 ? round($this->collectedRent($tenancyIds, $year, $month) / $expected * 100, 1) : 0.0;
    }

    /**
     * A transparent, factor-based health read on a single property — not a black-box score.
     * Every contributing factor is returned alongside the overall rating so a landlord can see why.
     *
     * @return array{rating: string, factors: array<string, mixed>}
     */
    public function healthScore(Property $property): array
    {
        $houseIds = $property->houses()->pluck('houses.id');
        $tenancyIds = $this->tenancyIdsFor(collect([$property->id]));

        $occupancy = $this->occupancyRate($houseIds);
        $collection = $this->collectionRate($tenancyIds, (int) now()->year, (int) now()->month);
        $arrears = $this->outstanding($tenancyIds);
        $openMaintenance = MaintenanceTask::where('property_id', $property->id)->open()->count();
        $urgentMaintenance = MaintenanceTask::where('property_id', $property->id)->open()->where('priority', 'emergency')->count();
        $expiringContracts = Tenancy::whereIn('house_id', $houseIds)->expiringSoon()->count();

        $issues = collect([
            $occupancy < 80 ? 'Occupancy below 80%' : null,
            $collection < 85 ? 'Collection rate below 85%' : null,
            $arrears > ($property->houses()->avg('monthly_rent') ?? 0) * 2 ? 'Arrears exceed two months\' average rent' : null,
            $urgentMaintenance > 0 ? "{$urgentMaintenance} emergency maintenance item(s) open" : null,
        ])->filter()->values();

        $rating = match (true) {
            $issues->count() >= 2 || $urgentMaintenance > 0 => 'Needs Attention',
            $issues->count() === 1 => 'Fair',
            default => 'Good',
        };

        return [
            'rating' => $rating,
            'factors' => [
                'occupancy' => $occupancy,
                'collection' => $collection,
                'arrears' => $arrears,
                'open_maintenance' => $openMaintenance,
                'urgent_maintenance' => $urgentMaintenance,
                'expiring_contracts' => $expiringContracts,
                'issues' => $issues,
            ],
        ];
    }
}
