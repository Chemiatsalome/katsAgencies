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
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // Set once the landlord deducts this task's cost from the
            // responsible tenant's deposit — non-null is also how the UI
            // knows not to offer deducting the same cost twice.
            $table->foreignId('deposit_deduction_id')->nullable()->after('expense_id')->constrained('deposit_deductions')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('deposit_deduction_id')->nullable()->after('recorded_by')->constrained('deposit_deductions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_deduction_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_deduction_id');
        });
    }
};
