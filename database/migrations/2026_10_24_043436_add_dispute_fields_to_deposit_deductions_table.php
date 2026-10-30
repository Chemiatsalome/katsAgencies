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
        Schema::table('deposit_deductions', function (Blueprint $table) {
            // A tenant can dispute a deduction instead of (or before) it being
            // voided outright — the deduction stays on the books, excluded
            // from the held-balance math while disputed, until the landlord
            // resolves it by either voiding it or upholding it (clearing the
            // dispute without changing anything else).
            $table->timestamp('disputed_at')->nullable()->after('voided_by');
            $table->text('dispute_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by')->nullable()->after('dispute_reason')->constrained('tenants')->nullOnDelete();
            $table->timestamp('dispute_resolved_at')->nullable()->after('disputed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deposit_deductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disputed_by');
            $table->dropColumn(['disputed_at', 'dispute_reason', 'dispute_resolved_at']);
        });
    }
};
