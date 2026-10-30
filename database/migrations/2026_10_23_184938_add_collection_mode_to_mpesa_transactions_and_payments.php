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
        // Which shortcode an M-Pesa payment actually settled into — 'platform'
        // (Kats' own paybill, held pending disbursement) or 'direct' (the
        // landlord's own shortcode, already theirs, nothing to disburse).
        // Needed so DisbursementService never counts a direct payment as
        // something Kats owes the landlord.
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->enum('collection_mode', ['platform', 'direct'])->nullable()->after('amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('collection_mode', ['platform', 'direct'])->nullable()->after('method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropColumn('collection_mode');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('collection_mode');
        });
    }
};
