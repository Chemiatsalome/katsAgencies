<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained('tenancies')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->unsignedTinyInteger('sequence_number');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('reason_category', ['noise_complaints', 'property_damage', 'rent_arrears', 'breach_of_agreement', 'other']);
            $table->text('reason_details')->nullable();
            $table->date('notice_date');
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->date('proposed_vacate_date')->nullable();

            $table->enum('status', ['issued', 'acknowledged', 'under_review', 'resolved', 'withdrawn', 'superseded', 'closed'])->default('issued');
            $table->timestamp('acknowledged_at')->nullable();

            $table->enum('tenant_response_type', ['dispute', 'request_time', 'request_extension'])->nullable();
            $table->text('tenant_response_note')->nullable();
            $table->date('requested_new_vacate_date')->nullable();

            $table->enum('decision', ['approve_time', 'approve_extension', 'decline'])->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->date('final_vacate_date')->nullable();
            $table->foreignId('new_contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdrawn_reason')->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_notice_id')->nullable()->constrained('tenancy_notices')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenancy_id', 'status']);
            $table->index(['tenancy_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_notices');
    }
};
