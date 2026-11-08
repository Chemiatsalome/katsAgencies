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
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // An SMS wallet top-up (purpose 'sms_topup') isn't tied to any
            // tenancy at all — it's the landlord paying Kats directly for
            // SMS credit, not a tenant paying rent/deposit.
            $table->foreignId('tenancy_id')->nullable()->change();
            $table->foreignId('landlord_id')->nullable()->after('tenancy_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landlord_id');
            $table->foreignId('tenancy_id')->nullable(false)->change();
        });
    }
};
