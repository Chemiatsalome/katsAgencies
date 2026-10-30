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
        Schema::create('late_fee_waiver_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rent_charge_id')->constrained('rent_charges')->cascadeOnDelete();
            // Denormalized off rent_charge_id purely so the landlord-side
            // list (and the tenant's own "do I already have a pending
            // request" check) never needs to join through rent_charges.
            $table->foreignId('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->decimal('requested_amount', 10, 2);
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['tenancy_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('late_fee_waiver_requests');
    }
};
