<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct maintenance/expense deposit charging: a landlord-initiated
     * deduction via the new unified forms no longer counts against the held
     * balance the instant it's recorded — it's a *proposed* charge until the
     * tenant acknowledges it or a dispute is resolved in the landlord's
     * favour. acknowledged_at is the single new signal that distinguishes
     * "nobody has responded yet" from "confirmed, either by acknowledgement
     * or by an upheld dispute." Every deduction recorded before this
     * migration — and every one still recorded through a pre-existing,
     * unchanged entry point (Tenancies\Show's generic deduction, Expenses\
     * Index's own existing buttons) — is backfilled/defaulted to
     * auto-acknowledged at creation, so nothing already counted against a
     * held balance stops counting.
     */
    public function up(): void
    {
        Schema::table('deposit_deductions', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('deduction_date');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('tenants')->nullOnDelete();
        });

        DB::table('deposit_deductions')->whereNull('acknowledged_at')->update(['acknowledged_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('deposit_deductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn('acknowledged_at');
        });
    }
};
