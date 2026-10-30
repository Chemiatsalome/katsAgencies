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
        Schema::table('rent_charges', function (Blueprint $table) {
            // The fee amount is also folded into amount_expected (so every
            // existing balance()/arrears calculation picks it up for free)
            // — these two columns exist purely for display/audit, and
            // late_fee_charged_at is what makes applying it idempotent.
            $table->decimal('late_fee_amount', 10, 2)->default(0)->after('amount_paid');
            $table->timestamp('late_fee_charged_at')->nullable()->after('late_fee_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rent_charges', function (Blueprint $table) {
            $table->dropColumn(['late_fee_amount', 'late_fee_charged_at']);
        });
    }
};
