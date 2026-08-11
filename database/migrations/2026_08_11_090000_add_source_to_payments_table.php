<?php

use App\Models\RentCharge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('source', [
                'system_payment', 'historical_import', 'seeded_data', 'manual_entry', 'opening_balance',
            ])->default('manual_entry')->after('notes');
        });

        // Classify existing rows by the markers already in use before this
        // column existed — the auto-generated "assumed paid" backfill and the
        // M-Pesa self-service flow both leave a recognizable notes string.
        // Everything else is left at the 'manual_entry' default, which is
        // correct and safe: those rows are already paid and nobody's asking
        // to revert them.
        DB::table('payments')->where('notes', RentCharge::ASSUMED_PAID_NOTE)->update(['source' => 'opening_balance']);
        DB::table('payments')->where('notes', 'Paid via M-Pesa (tenant self-service)')->update(['source' => 'system_payment']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
