<?php

namespace App\Console\Commands;

use App\Services\RentScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rent:generate-charges {--year=} {--month=} {--landlord=}')]
#[Description('Generate this month\'s (or a given period\'s) rent charges for every tenancy active during that period, optionally scoped to one landlord.')]
class GenerateMonthlyRentCharges extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RentScheduleService $rentSchedule): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);
        $landlordId = $this->option('landlord') ? (int) $this->option('landlord') : null;

        $created = $rentSchedule->ensureChargesForPeriod($landlordId, null, $year, $month);

        $this->info("Generated {$created} rent charge(s) for {$year}-{$month}.");

        return self::SUCCESS;
    }
}
