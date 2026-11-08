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
        Schema::create('sms_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 10, 2);
            // Set on a credit row once the top-up STK push actually
            // confirms — see MpesaCallbackController's sms_topup branch.
            $table->foreignId('mpesa_transaction_id')->nullable()->constrained('mpesa_transactions')->nullOnDelete();
            // Set on a debit row — traces the charge back to exactly which
            // SMS it paid for.
            $table->foreignId('app_notification_id')->nullable()->constrained('app_notifications')->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['landlord_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_wallet_entries');
    }
};
