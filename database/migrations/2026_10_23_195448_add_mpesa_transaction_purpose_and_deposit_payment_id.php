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
            // Lets one STK-push pipeline serve both rent and deposit
            // payments — the callback allocates to RentCharge/Payment for
            // 'rent', or DepositPayment for 'deposit', based on this.
            $table->string('purpose')->default('rent')->after('amount');
            $table->foreignId('deposit_payment_id')->nullable()->after('payment_id')->constrained('deposit_payments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_payment_id');
            $table->dropColumn('purpose');
        });
    }
};
