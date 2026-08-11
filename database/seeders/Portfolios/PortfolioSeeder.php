<?php

namespace Database\Seeders\Portfolios;

use App\Models\Block;
use App\Models\DepositPayment;
use App\Models\House;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentCharge;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

abstract class PortfolioSeeder extends Seeder
{
    protected function landlord(): User
    {
        return User::where('email', 'landlord@katsagencies.test')->firstOrFail();
    }

    protected function property(string $name, ?string $address = null): Property
    {
        return Property::firstOrCreate(
            ['name' => $name],
            ['landlord_id' => $this->landlord()->id, 'address' => $address, 'status' => 'active']
        );
    }

    protected function block(Property $property, string $name): Block
    {
        return Block::firstOrCreate(['property_id' => $property->id, 'name' => $name]);
    }

    protected function house(
        Block $block,
        string $number,
        string $type,
        float $rent,
        float $deposit = 0,
        string $electricity = 'separate_meter',
        string $water = 'shared',
        ?string $description = null,
    ): House {
        return House::firstOrCreate(
            ['block_id' => $block->id, 'house_number' => $number],
            [
                'type' => $type,
                'monthly_rent' => $rent,
                'deposit_amount' => $deposit,
                'electricity_arrangement' => $electricity,
                'water_arrangement' => $water,
                'status' => 'vacant',
                'description' => $description,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $tenantAttrs  ['name' => ..., 'phone' => ..., 'payment_due_day' => ..., 'notes' => ...]
     */
    protected function moveIn(
        House $house,
        array $tenantAttrs,
        string $moveInDate,
        ?float $monthlyRent = null,
        float $depositRequired = 0,
        ?string $notes = null,
    ): Tenancy {
        $tenant = Tenant::firstOrCreate(
            ['phone' => $tenantAttrs['phone']],
            $tenantAttrs + ['payment_due_day' => 1]
        );

        return Tenancy::firstOrCreate(
            ['tenant_id' => $tenant->id, 'house_id' => $house->id, 'move_in_date' => $moveInDate],
            [
                'monthly_rent' => $monthlyRent ?? $house->monthly_rent,
                'deposit_required' => $depositRequired,
                'status' => 'active',
                'notes' => $notes,
            ]
        );
    }

    /**
     * Apply a month-by-month payment schedule to a tenancy, converting historical
     * spreadsheet marks into RentCharge + Payment (+ DepositPayment) rows.
     *
     * Each entry in $schedule is keyed by month (1-12) with a spec array:
     *   - 'paid' => amount        create the month's charge and a payment of this amount
     *   - 'note' => string        explanatory note attached to the rent charge
     *   - 'deposit' => amount     also records a deposit payment dated in this month
     *   - 'no_charge' => true     only record the deposit payment, skip the rent charge
     */
    protected function applySchedule(Tenancy $tenancy, array $schedule, int $year = 2024): void
    {
        $dueDay = $tenancy->tenant->payment_due_day;

        foreach ($schedule as $month => $spec) {
            $daysInMonth = Carbon::create($year, $month)->daysInMonth;
            $dueDate = Carbon::create($year, $month, min($dueDay, $daysInMonth));

            if (isset($spec['deposit'])) {
                DepositPayment::create([
                    'tenancy_id' => $tenancy->id,
                    'amount' => $spec['deposit'],
                    'payment_date' => $dueDate,
                    'method' => 'other',
                    'notes' => 'Seeded from historical spreadsheet records.',
                ]);
            }

            if (! empty($spec['no_charge'])) {
                continue;
            }

            $charge = RentCharge::firstOrCreate(
                ['tenancy_id' => $tenancy->id, 'period_year' => $year, 'period_month' => $month],
                [
                    'due_date' => $dueDate,
                    'amount_expected' => $spec['expected'] ?? $tenancy->monthly_rent,
                    'status' => isset($spec['paid']) ? 'unpaid' : ($dueDate->isPast() ? 'overdue' : 'unpaid'),
                    'notes' => $spec['note'] ?? null,
                ]
            );

            if (! empty($spec['paid'])) {
                Payment::create([
                    'tenancy_id' => $tenancy->id,
                    'rent_charge_id' => $charge->id,
                    'amount' => $spec['paid'],
                    'payment_date' => $dueDate,
                    'method' => 'other',
                    'notes' => 'Seeded from historical spreadsheet records.',
                    'source' => 'seeded_data',
                ]);
            }
        }
    }
}
