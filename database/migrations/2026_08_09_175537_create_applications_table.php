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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desired_property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('desired_house_id')->nullable()->constrained('houses')->nullOnDelete();
            $table->string('applicant_name');
            $table->string('applicant_phone');
            $table->string('applicant_email')->nullable();
            $table->date('application_date');
            $table->enum('status', [
                'submitted', 'under_review', 'more_info_required', 'approved', 'rejected', 'withdrawn',
            ])->default('submitted');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('converted_tenancy_id')->nullable()->constrained('tenancies')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
