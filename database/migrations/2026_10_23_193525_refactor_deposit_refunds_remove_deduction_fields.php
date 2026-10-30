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
        // Deductions are now their own itemised, dated, evidenced records
        // (deposit_deductions) instead of one lump sum bundled into whatever
        // refund happened to be recorded at the same time — a deduction for
        // mid-tenancy damage no longer has to wait for a refund event to
        // exist at all. No existing deposit_refunds row has ever used these
        // two columns (checked before writing this migration), so there's
        // nothing to carry over.
        Schema::table('deposit_refunds', function (Blueprint $table) {
            $table->dropColumn(['deductions', 'deduction_reason']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deposit_refunds', function (Blueprint $table) {
            $table->decimal('deductions', 10, 2)->default(0);
            $table->text('deduction_reason')->nullable();
        });
    }
};
