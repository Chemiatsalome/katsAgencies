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
        Schema::create('houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->string('house_number');
            $table->enum('type', [
                'bedsitter', 'studio', 'sq', 'one_bedroom', 'two_bedroom', 'three_bedroom',
                'four_bedroom', 'house', 'maisonette', 'shop', 'office', 'other',
            ])->default('other');
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->decimal('monthly_rent', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->enum('electricity_arrangement', ['shared', 'separate_meter', 'included'])->default('separate_meter');
            $table->enum('water_arrangement', ['shared', 'separate_meter', 'included'])->default('shared');
            $table->enum('status', [
                'vacant', 'occupied', 'reserved', 'pending_approval', 'under_maintenance', 'unavailable',
            ])->default('vacant');
            $table->date('availability_date')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['block_id', 'house_number']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('houses');
    }
};
