<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\MaintenanceTask;
use App\Services\TenantLiabilityDecisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Tier 2 (design approved 2026-10-25), §3/§7 deployment gate: read-only —
 * never creates or changes anything. Lists every Expense/MaintenanceTask
 * that already has a cost but no deposit deduction and no recorded
 * liability decision yet, so a human can triage each one explicitly
 * before the new deposit-deduction UI goes live. Nothing is
 * auto-assigned; this command's only job is to make the list visible.
 */
#[Signature('liability:triage-report')]
#[Description('Read-only: lists every costed expense/maintenance task with no deposit deduction and no recorded liability decision yet, for manual triage before deployment.')]
class LiabilityTriageReport extends Command
{
    public function handle(TenantLiabilityDecisionService $service): int
    {
        $expenses = Expense::whereNull('deposit_deduction_id')
            ->where('amount', '>', 0)
            ->with('property', 'house')
            ->get()
            ->filter(fn (Expense $e) => ! $service->activeDecisionFor($e));

        $tasks = MaintenanceTask::whereNull('deposit_deduction_id')
            ->where('actual_cost', '>', 0)
            ->with('property', 'house', 'complaint')
            ->get()
            ->filter(fn (MaintenanceTask $t) => ! $service->activeDecisionFor($t));

        $this->info("Expenses needing a liability decision: {$expenses->count()}");
        foreach ($expenses as $expense) {
            $candidate = app(\App\Livewire\Expenses\Index::class)->candidateTenancyFor($expense);
            $this->line("  Expense #{$expense->id} — {$expense->property->name}".($expense->house ? ', '.$expense->house->house_number : '')
                .' — KSh '.number_format($expense->amount, 2)." on {$expense->expense_date->format('d M Y')}"
                .' — candidate: '.($candidate['kind'] === 'single' ? $candidate['tenancy']->tenant->name : $candidate['kind']));
        }

        $this->info("Maintenance tasks needing a liability decision: {$tasks->count()}");
        foreach ($tasks as $task) {
            $candidate = app(\App\Services\MaintenanceCostService::class)->candidateTenancyFor($task);
            $this->line("  Task #{$task->id} — {$task->title} — KSh ".number_format((float) $task->actual_cost, 2)
                .' — candidate: '.($candidate['kind'] === 'single' ? $candidate['tenancy']->tenant->name : $candidate['kind']));
        }

        $this->comment('Nothing above has been changed — this is a read-only report. Each item needs an explicit landlord decision (Decide Liability) before it can ever be deducted from a deposit.');

        return self::SUCCESS;
    }
}
