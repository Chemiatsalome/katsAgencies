<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * G-1A: at most one wallet entry per AppNotification, enforced by the
     * database rather than by careful call-site sequencing alone. Verified
     * against the real dev DB before writing this migration: 0 debit rows
     * share an app_notification_id, and every credit row (top-ups) already
     * has app_notification_id = null — MySQL's unique index treats each
     * NULL as distinct, so top-up credits remain unaffected; this only
     * ever constrains the (app_notification_id, 'debit') pairs that G-2's
     * debit()/recordUnbilledSend() write.
     */
    public function up(): void
    {
        Schema::table('sms_wallet_entries', function (Blueprint $table) {
            $table->unique(['app_notification_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_wallet_entries', function (Blueprint $table) {
            $table->dropUnique(['app_notification_id', 'type']);
        });
    }
};
