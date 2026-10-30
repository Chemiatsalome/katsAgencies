<?php

namespace App\Console\Commands;

use App\Services\RentScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rent:apply-late-fees {--landlord=}')]
#[Description('Add a one-time late fee to every outstanding rent charge past its landlord\'s grace period. No-op for a landlord who hasn\'t enabled late fees in Settings.')]
class ApplyLateFees extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RentScheduleService $rentSchedule): int
    {
        $landlordId = $this->option('landlord') ? (int) $this->option('landlord') : null;

        $applied = $rentSchedule->applyLateFees($landlordId);

        $this->info("Applied {$applied} late fee(s).");

        return self::SUCCESS;
    }
}
