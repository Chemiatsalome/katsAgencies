<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8 (design approved 2026-10-25): nullable, additive, no backfill. Copied
 * forward from MaintenanceTask::tenancy_id only when an expense is
 * generated from a task (MaintenanceCostService::recordExpenseFor()/
 * recordAdditionalExpenseFor()) — NULL for a landlord's direct manual
 * entry (Expenses\Form), which has no tenancy context to capture at all.
 * Never used to establish tenant liability on its own — that remains
 * exclusively TenantLiabilityDecisionService's job (§7), untouched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('tenancy_id')->nullable()->after('house_id')->constrained('tenancies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenancy_id');
        });
    }
};
