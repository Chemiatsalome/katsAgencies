<?php

namespace App\Console\Commands;

use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('maintenance:generate-recurring-tasks')]
#[Description('Generate a maintenance task for every preventive schedule that is now due, and roll its next due date forward.')]
class GenerateRecurringMaintenanceTasks extends Command
{
    public function handle(): int
    {
        $created = 0;

        MaintenanceSchedule::due()->with('property')->each(function (MaintenanceSchedule $schedule) use (&$created) {
            MaintenanceTask::create([
                'property_id' => $schedule->property_id,
                'maintenance_schedule_id' => $schedule->id,
                'title' => $schedule->title,
                'category' => $schedule->category,
                'description' => $schedule->notes,
                'priority' => 'medium',
                'status' => 'submitted',
            ]);

            $schedule->update([
                'last_completed_date' => now()->toDateString(),
                'next_due_date' => now()->addMonths($schedule->frequency_months)->toDateString(),
            ]);

            $created++;
        });

        $this->info("Generated {$created} recurring maintenance task(s).");

        return self::SUCCESS;
    }
}
