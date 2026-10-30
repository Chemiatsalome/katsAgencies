<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mpesa_transactions.payment_id is a single nullable FK, but
 * PaymentAllocationService::allocate() can produce several Payment rows
 * from one callback (one per charge touched + one overflow row) —
 * MpesaCallbackController only ever linked the first. This column is the
 * complete, authoritative way to ask "every payment this callback
 * produced." mpesa_transactions.payment_id is left exactly as-is
 * (legacy/first-only); repurposing or dropping it is out of scope here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('mpesa_transaction_id')->nullable()->after('rent_charge_id')
                ->constrained('mpesa_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mpesa_transaction_id');
        });
    }
};
