<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Disbursement row used to be treated as settled the instant an admin
 * recorded it — correct that the transfer happened (it's always made
 * outside the system first), but wrong to silently assume the landlord
 * actually received it. This mirrors the confirmation workflow already
 * built for Payment (see add_confirmation_workflow_to_payments_table):
 * every disbursement now starts 'awaiting_landlord_confirmation' and the
 * landlord explicitly confirms or disputes it. Unlike Payment, confirming
 * or disputing a disbursement never changes DisbursementService::owed() —
 * only an admin's explicit reverse()/markFailed() does, since the money
 * already left Kats' hands the moment it was recorded, not when the
 * landlord acknowledges it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->string('status', 30)->default('awaiting_landlord_confirmation')->after('amount');
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable()->after('confirmed_by');
            $table->text('dispute_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by')->nullable()->after('dispute_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('disputed_by');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->foreignId('reversed_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->date('payout_period_start')->nullable()->after('reversed_by');
            $table->date('payout_period_end')->nullable()->after('payout_period_start');

            $table->index('status');
            // Nullable-safe: MySQL/Postgres/SQLite all treat multiple NULLs
            // as distinct under a unique index, so blank references stay
            // unrestricted while a real duplicate M-Pesa/bank code is
            // rejected at the database layer, not just in validation.
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'payout_period_end', 'payout_period_start',
            ]);
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversal_reason');
            $table->dropColumn('reversed_at');
            $table->dropConstrainedForeignId('disputed_by');
            $table->dropColumn('dispute_reason');
            $table->dropColumn('disputed_at');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
            $table->dropColumn('status');
        });
    }
};
