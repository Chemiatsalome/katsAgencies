<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejected_landlord_applications', function (Blueprint $table) {
            $table->id();

            // A snapshot, not a reference back to the users table — the
            // whole point is that this survives the applicant's User row
            // being deleted, so a later application (even under a
            // different email) can still be matched against it by phone
            // or national ID.
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable();
            $table->string('business_name')->nullable();
            $table->string('property_location')->nullable();
            $table->unsignedInteger('intended_unit_count')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            $table->index('phone');
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_landlord_applications');
    }
};
