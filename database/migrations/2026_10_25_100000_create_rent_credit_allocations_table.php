<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 2 rent-credit allocation ledger (design approved 2026-10-25).
 * Additive only — RentCharge/Payment columns are untouched, and nothing
 * writes here yet (RentCreditAllocationService is built and unit-tested in
 * this phase but not wired into any live write path).
 *
 * source_payment_id is the mandatory traceability anchor (never
 * source_rent_charge_id alone — a charge can carry several payments from
 * different sources). allocation_group_id ties together the rows produced
 * by one logical top-up (e.g. a 5,000 draw spanning two source payments'
 * slices) for display/reversal convenience only — the ledger truth is
 * always the individual row. No unique constraint on
 * (source_payment_id, target_rent_charge_id): a reversal-then-reallocate
 * correction legitimately produces two rows for the same pair over time;
 * double-spend is prevented by locking + re-verification inside a
 * transaction (RentCreditAllocationService), not a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('allocation_group_id');
            $table->foreignId('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->foreignId('source_payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('source_rent_charge_id')->constrained('rent_charges')->cascadeOnDelete();
            $table->foreignId('target_rent_charge_id')->constrained('rent_charges')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('attribution_basis', ['exact', 'reconstructed_fifo']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('allocation_group_id');
            $table->index('source_rent_charge_id');
            $table->index('target_rent_charge_id');
            $table->index('tenancy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_credit_allocations');
    }
};
