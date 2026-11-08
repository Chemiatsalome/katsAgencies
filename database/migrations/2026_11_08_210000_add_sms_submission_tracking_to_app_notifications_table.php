<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * G-1A: SMS delivery outcome and billing outcome, tracked independently
     * of the existing `status` column (which keeps its current meaning for
     * every channel, including SMS, so no existing query/UI/test is
     * affected). Null for every in_app/email row, and for an sms row before
     * its first delivery attempt.
     *
     * `submission_token`/`submission_started_at`/`submission_attempt`
     * implement the atomic claim a worker takes before contacting Africa's
     * Talking — see NotificationService::deliverSms().
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->enum('delivery_status', ['pending', 'submitting', 'sent', 'failed', 'submission_unknown'])
                ->nullable()->after('status');
            $table->enum('billing_status', ['pending', 'charged', 'reconciliation_required', 'not_chargeable'])
                ->nullable()->after('delivery_status');
            $table->string('submission_token')->nullable()->after('billing_status');
            $table->timestamp('submission_started_at')->nullable()->after('submission_token');
            $table->unsignedInteger('submission_attempt')->default(0)->after('submission_started_at');

            $table->index(['delivery_status', 'submission_started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex(['delivery_status', 'submission_started_at']);
            $table->dropColumn(['delivery_status', 'billing_status', 'submission_token', 'submission_started_at', 'submission_attempt']);
        });
    }
};
