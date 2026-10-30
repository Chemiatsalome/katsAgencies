<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->enum('category', ['monthly_rent', 'rent_due_date', 'security_deposit', 'tenancy_agreement', 'property_unit_details', 'other']);
            // Snapshotted server-side from the tenancy at submission time, not
            // tenant-typed — so the landlord sees what the tenant was actually
            // looking at even if the real value changes before a decision.
            $table->string('current_value')->nullable();
            $table->text('requested_change');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'under_review', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['tenancy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_change_requests');
    }
};
