<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Payment row was treated as confirmed money the instant it existed
 * — fine for an M-Pesa callback (Safaricom already verified it) or a
 * landlord typing in something they've already checked themselves, but
 * wrong for a tenant claiming "I paid offline" with no verification at
 * all. `status` defaults to 'confirmed' so every existing creation path
 * (STK success, manual landlord entry, historical/seeded/opening-balance)
 * keeps working exactly as before — only the new tenant-submitted-offline
 * path starts at 'awaiting_landlord_confirmation' and is excluded from
 * every ledger sum until a landlord actually confirms it (see
 * Payment::scopeConfirmed()/scopeGenuine(), RentCharge::recalculateStatus()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 30)->default('confirmed')->after('source');
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable()->after('confirmed_by');
            $table->text('dispute_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by')->nullable()->after('dispute_reason')->constrained('users')->nullOnDelete();

            $table->index('status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('source', [
                'system_payment', 'historical_import', 'seeded_data', 'manual_entry', 'opening_balance',
                'tenant_submitted_offline',
            ])->default('manual_entry')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('source', [
                'system_payment', 'historical_import', 'seeded_data', 'manual_entry', 'opening_balance',
            ])->default('manual_entry')->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disputed_by');
            $table->dropColumn('dispute_reason');
            $table->dropColumn('disputed_at');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
            $table->dropColumn('status');
        });
    }
};
