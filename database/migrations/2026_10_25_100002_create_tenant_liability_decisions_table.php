<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 2 maintenance-liability decision audit trail (design approved
 * 2026-10-25) — entirely separate from Expense/MaintenanceTask (no
 * tenancy_id added to either) and from DepositDeduction. Occupancy
 * (Tenancy::occupyingOn()) is a candidate signal only; this table is the
 * only place an actual liability decision is recorded, always by a human
 * (decided_by is NOT NULL). Amendment = reverse the old row, create a new
 * one with superseded_by_id pointing back — never edited in place.
 *
 * tenant_liable and landlord_responsible are not two independent flags:
 * they are the two possible values of decision_type on whichever single
 * row currently holds the "active" (unreversed) slot for a given
 * decidable record — enforced primarily by locking the parent
 * Expense/MaintenanceTask row before creating/amending/reversing a
 * decision (RentCreditAllocationService's sibling, the liability-decision
 * service, does this). The generated-column unique index below is a
 * database-level backstop, applied only where it's actually verified to
 * work: this app runs MariaDB 10.4.32 in production (confirmed via
 * `select version()`), which supports a secondary/unique index on a
 * virtual generated column for InnoDB; the SQLite test driver's generated-
 * column + partial-unique-index support was not confirmed to behave
 * identically, so — matching this codebase's existing precedent in
 * 2026_08_10_090001_add_advance_status_to_rent_charges_table.php — the
 * backstop is skipped on SQLite and the application-level lock remains the
 * sole guard there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_liability_decisions', function (Blueprint $table) {
            $table->id();
            $table->morphs('decidable');
            $table->enum('decision_type', ['tenant_liable', 'landlord_responsible']);
            $table->foreignId('tenancy_id')->nullable()->constrained('tenancies')->nullOnDelete();
            $table->foreignId('decided_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('decided_at');
            $table->text('reason');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('tenant_liability_decisions')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tenant_liability_decisions', function (Blueprint $table) {
                $table->string('active_marker', 300)->nullable()
                    ->virtualAs("IF(reversed_at IS NULL, CONCAT(decidable_type, '-', decidable_id), NULL)")
                    ->unique();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_liability_decisions');
    }
};
