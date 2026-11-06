<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors add_confirmation_workflow_to_payments_table exactly, same
 * reasoning: every DepositPayment row was treated as confirmed money the
 * instant it existed — fine for an M-Pesa callback or a landlord recording
 * a transfer they've already verified themselves, but there was no path at
 * all for a tenant's own unverified "I paid my deposit offline" claim, and
 * none should ever count toward the held deposit until a landlord actually
 * confirms it. `status` defaults to 'confirmed' so both existing creation
 * paths (MpesaCallbackController, Deposits\RecordDeposit) keep working
 * exactly as before with no code changes — only the new tenant-submitted
 * path starts at 'awaiting_landlord_confirmation' and is excluded from
 * Tenancy.deposit_paid until confirmed (see DepositPaymentObserver,
 * DepositPayment::scopeConfirmed()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_payments', function (Blueprint $table) {
            $table->string('status', 30)->default('confirmed')->after('notes');
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable()->after('confirmed_by');
            $table->text('dispute_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by')->nullable()->after('dispute_reason')->constrained('users')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disputed_by');
            $table->dropColumn('dispute_reason');
            $table->dropColumn('disputed_at');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
            $table->dropColumn('status');
        });
    }
};
