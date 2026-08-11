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
        Schema::create('tenancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->date('move_in_date');
            $table->date('move_out_date')->nullable();
            $table->date('lease_end_date')->nullable();
            $table->decimal('monthly_rent', 10, 2);
            $table->decimal('deposit_required', 10, 2)->default(0);
            $table->decimal('deposit_paid', 10, 2)->default(0);
            $table->enum('status', [
                'pending', 'active', 'expiring_soon', 'notice_given', 'terminated', 'completed',
            ])->default('active');
            $table->date('notice_date')->nullable();
            $table->string('termination_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['house_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenancies');
    }
};
