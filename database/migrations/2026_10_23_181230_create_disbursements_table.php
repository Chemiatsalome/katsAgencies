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
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->date('disbursed_at');
            // Snapshot of the landlord's payout destination at the time —
            // kept even if they later change their settings, so the record
            // always shows where the money actually went.
            $table->enum('destination_type', ['paybill', 'till'])->nullable();
            $table->string('destination_shortcode')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['landlord_id', 'disbursed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
