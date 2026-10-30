<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->unsignedSmallInteger('requested_duration_months');
            $table->date('preferred_new_end_date')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'under_review', 'alternative_proposed', 'approved', 'declined'])->default('pending');

            // A landlord's counter-offer — distinct columns from the
            // tenant's original request so both sides of the negotiation
            // stay on record rather than one overwriting the other.
            $table->unsignedSmallInteger('alternative_duration_months')->nullable();
            $table->date('alternative_new_end_date')->nullable();
            $table->text('alternative_note')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Whichever date actually took effect — the original request's,
            // or the alternative's if that's the one that got accepted.
            $table->date('final_new_end_date')->nullable();
            $table->foreignId('new_contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenancy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_renewal_requests');
    }
};
