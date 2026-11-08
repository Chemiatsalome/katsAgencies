<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * G-1A admin reconciliation: the evidence an admin attests to when
     * manually resolving a `submission_unknown` notification —
     * NotificationService::reconcileManually(). Recorded directly on the
     * row (so the current resolution is visible without joining the
     * activity log) and, via AppNotification's LogsActivity trait, also
     * captured historically in the existing activity_log table alongside
     * who made the call and when.
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->text('reconciliation_note')->nullable()->after('submission_attempt');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_note');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn(['reconciliation_note', 'reconciled_at']);
        });
    }
};
