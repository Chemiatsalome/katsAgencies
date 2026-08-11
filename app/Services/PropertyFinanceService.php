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

    public function outstanding(Collection $tenancyIds): float
    {
        return (float) RentCharge::outstanding()
            ->whereIn('tenancy_id', $tenancyIds)
            ->get()
            ->sum(fn (RentCharge $c) => $c->amount_expected - $c->amount_paid);
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
