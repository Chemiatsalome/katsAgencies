<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            // Drives the late-fee grace period rule in
            // RentScheduleService::applyLateFees() — a tenant who
            // specifically asked for this due date gets zero grace, whereas
            // everyone left on the system default gets the configured
            // grace period. Only ever flips true once a DueDayChangeRequest
            // is actually accepted (see that table) — never set directly.
            $table->boolean('due_day_requested_by_tenant')->default(false)->after('rent_due_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropColumn('due_day_requested_by_tenant');
        });
    }
};
