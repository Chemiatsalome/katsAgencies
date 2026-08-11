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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', [
                'apartment', 'residential_compound', 'individual_house', 'maisonette',
                'commercial_building', 'mixed_use', 'other',
            ])->default('other');
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('initial_renovation_cost', 12, 2)->nullable();
            $table->decimal('other_acquisition_costs', 12, 2)->nullable();
            $table->decimal('current_estimated_value', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
